<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Exports\FinancialStatementExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\OperatingBox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;

class EstadoDeResultadosController extends Controller
{
    /**
     * Obtener estado de resultados global (negocio + cajas operativas) en rango de fechas
     *
     * REGLA DE NEGOCIO GLOBAL:
     * - INGRESOS GLOBALES: Solo se cuentan los que NO tienen caja_operativa_id (evita duplicar movimientos internos de recargas a cajas).
     * - EGRESOS GLOBALES: Se cuentan TODOS (incluye los de caja operativa, son gastos reales del negocio).
     * - CAJAS OPERATIVAS: Se calculan por separado sus recargas (ingresos a caja) y subtracciones (egresos de caja) para auditoría interna.
     * - BALANCE GLOBAL: Ingresos globales - Egresos globales (cajas afectan solo egresos si son gastos, pero recargas no se suman a ingresos globales).
     * - Se considera el saldo actual de las cajas para comparación y auditoría.
     * - DETALLE POR CAJA: Incluye desglose por estados de transacción (pagado, por pagar, etc.) para recargas y subtracciones específicas de cada caja.
     */
    public function getFinancialStatementByDateRange(Request $request): JsonResponse
    {
        // ============== VALIDACIÓN DE PARÁMETROS ==============
        $validator = Validator::make($request->all(), [
            'negocio_id' => ['required', 'exists:businesses,id'],
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ], [
            'negocio_id.required' => 'El ID del negocio es obligatorio',
            'negocio_id.exists' => 'El negocio seleccionado no existe',
            'fecha_inicial.required' => 'La fecha inicial es obligatoria',
            'fecha_inicial.date' => 'La fecha inicial debe ser una fecha válida',
            'fecha_final.required' => 'La fecha final es obligatoria',
            'fecha_final.date' => 'La fecha final debe ser una fecha válida',
            'fecha_final.after_or_equal' => 'La fecha final debe ser posterior o igual a la fecha inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $negocioId = $request->negocio_id;
        $fechaInicial = $request->fecha_inicial;
        $fechaFinal = $request->fecha_final;

        try {
            // ============== INFORMACIÓN DEL NEGOCIO ==============
            $negocio = Business::findOrFail($negocioId);
            Log::info('Procesando estado financiero global para negocio', [
                'negocio_id' => $negocioId,
                'negocio_nombre' => $negocio->nombre,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CALCULAR TOTALES GLOBALES DEL NEGOCIO ==============
            // Ingresos: Solo sin caja operativa (movimientos directos del negocio)
            $totalIngresosBrutos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            // Egresos: TODOS (incluye egresos directos del negocio y de cajas operativas)
            $totalEgresosBrutos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                // NO SE EXCLUYE caja_operativa_id - Se incluyen TODOS los egresos reales
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            $margenBruto = $totalIngresosBrutos - $totalEgresosBrutos;

            // Margen Util Antes de Impuestos (establecido en 0, sin deducciones)
            $margenUtilAntesImpuestos = 0;
            $impuestosEstimados = 0;
            $costosFijosAdicionales = 0;

            // Rentabilidad basada solo en margen bruto (sin considerar util antes de impuestos)
            $rentabilidadPorcentaje = $totalIngresosBrutos > 0
                ? ($margenBruto / $totalIngresosBrutos) * 100
                : 0;

            Log::info('Totales globales calculados', [
                'negocio_id' => $negocioId,
                'total_ingresos_brutos' => $totalIngresosBrutos,
                'total_egresos_brutos' => $totalEgresosBrutos,
                'margen_bruto' => $margenBruto,
                'margen_util_antes_impuestos' => $margenUtilAntesImpuestos
            ]);

            // ============== OBTENER CAJAS OPERATIVAS CON TRANSACCIONES ==============
            // Solo cajas que tengan transacciones en el período (para auditoría interna)
            $cajasConTransacciones = FinancialTransactions::where('negocio_id', $negocioId)
                ->whereNotNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->select('caja_operativa_id')
                ->distinct()
                ->pluck('caja_operativa_id');

            $cajasOperativas = OperatingBox::whereIn('id', $cajasConTransacciones)
                ->where('estado', true) // Solo cajas activas
                ->get();

            // ============== CALCULAR ESTADO POR CAJA OPERATIVA (AUDITORÍA INTERNA CON DETALLE POR ESTADO) ==============
            $estadoPorCaja = [];
            $totalesGlobalesCajas = [
                'total_ingresos_cajas' => 0, // Recargas a cajas (no afectan ingresos globales)
                'total_egresos_cajas' => 0,  // Subtracciones de cajas (ya incluidos en egresos globales)
                'balance_global_cajas' => 0  // Neto de movimientos en cajas (para control interno)
            ];

            foreach ($cajasOperativas as $caja) {
                // Totales generales por caja (recargas y subtracciones)
                $ingresosCaja = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                $egresosCaja = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                $balanceCaja = $ingresosCaja - $egresosCaja;

                // Contar transacciones totales por caja
                $totalTransaccionesIngresos = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->count();

                $totalTransaccionesEgresos = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->count();

                // ============== DETALLE POR ESTADO PARA ESTA CAJA ==============
                // Transacciones específicas de la caja (ingresos y egresos con caja_operativa_id)
                $transaccionesPorEstadoCaja = FinancialTransactions::where('negocio_id', $negocioId)
                    ->where('caja_operativa_id', $caja->id)
                    ->where(function ($query) {
                        // Ingresos (recargas) de la caja
                        $query->where('tipo_de_transaccion', 'Ingreso')
                            // O egresos (subtracciones) de la caja
                            ->orWhere('tipo_de_transaccion', 'Egreso');
                    })
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                    ->select(
                        'transaction_states.id as estado_id',
                        'transaction_states.nombre as estado_nombre',
                        'transaction_states.descripcion as estado_descripcion',
                        'financial_transactions.tipo_de_transaccion',
                        DB::raw('COUNT(*) as total_transacciones'),
                        DB::raw('SUM(importe_total) as total_importe')
                    )
                    ->groupBy(
                        'transaction_states.id',
                        'transaction_states.nombre',
                        'transaction_states.descripcion',
                        'financial_transactions.tipo_de_transaccion'
                    )
                    ->get();

                // Organizar por estado para esta caja
                $estadosPorCaja = [];
                foreach ($transaccionesPorEstadoCaja as $transaccion) {
                    $estadoId = $transaccion->estado_id;
                    $estadoNombre = strtoupper($transaccion->estado_nombre);
                    $tipo = $transaccion->tipo_de_transaccion;

                    if (!isset($estadosPorCaja[$estadoId])) {
                        $estadosPorCaja[$estadoId] = [
                            'estado_id' => $estadoId,
                            'estado_nombre' => $estadoNombre,
                            'estado_descripcion' => $transaccion->estado_descripcion,
                            'ingresos_recargas' => 0, // Específico: recargas pagadas/por pagar
                            'egresos_subtracciones' => 0, // Específico: subtracciones pagadas/por pagar
                            'total_transacciones_recargas' => 0,
                            'total_transacciones_subtracciones' => 0,
                            'balance_estado_caja' => 0
                        ];
                    }

                    if ($tipo === 'Ingreso') {
                        $estadosPorCaja[$estadoId]['ingresos_recargas'] = floatval($transaccion->total_importe);
                        $estadosPorCaja[$estadoId]['total_transacciones_recargas'] = intval($transaccion->total_transacciones);
                    } else {
                        $estadosPorCaja[$estadoId]['egresos_subtracciones'] = floatval($transaccion->total_importe);
                        $estadosPorCaja[$estadoId]['total_transacciones_subtracciones'] = intval($transaccion->total_transacciones);
                    }

                    $estadosPorCaja[$estadoId]['balance_estado_caja'] =
                        $estadosPorCaja[$estadoId]['ingresos_recargas'] - $estadosPorCaja[$estadoId]['egresos_subtracciones'];
                }

                // Promedios para cajas
                $promedioIngreso = $totalTransaccionesIngresos > 0 ? $ingresosCaja / $totalTransaccionesIngresos : 0;
                $promedioEgreso = $totalTransaccionesEgresos > 0 ? $egresosCaja / $totalTransaccionesEgresos : 0;

                $estadoPorCaja[] = [
                    'caja_operativa' => [
                        'id' => $caja->id,
                        'nombre' => strtoupper($caja->nombre),
                        'descripcion' => $caja->descripcion ?? 'Sin descripción',
                        'saldo_actual' => floatval($caja->saldo), // Saldo actual en BD
                    ],
                    'periodo' => [
                        'ingresos_recargas' => floatval($ingresosCaja), // Recargas totales
                        'egresos_subtracciones' => floatval($egresosCaja),   // Subtracciones totales
                        'balance_periodo' => floatval($balanceCaja), // Neto interno de caja
                    ],
                    'transacciones_totales' => [
                        'total_recargas' => intval($totalTransaccionesIngresos),
                        'total_subtracciones' => intval($totalTransaccionesEgresos),
                        'total_transacciones_caja' => intval($totalTransaccionesIngresos + $totalTransaccionesEgresos),
                    ],
                    'promedios' => [
                        'promedio_recarga' => round($promedioIngreso, 2),
                        'promedio_subtraccion' => round($promedioEgreso, 2),
                    ],
                    'rentabilidad_caja' => $ingresosCaja > 0 ? round((($ingresosCaja - $egresosCaja) / $ingresosCaja) * 100, 2) : 0, // % de retención de recargas
                    'diferencia_saldo' => floatval($caja->saldo - $balanceCaja), // Diferencia con saldo actual (auditoría)
                    'detalle_por_estado' => array_values($estadosPorCaja), // Nuevo: Desglose por estados (pagado, por pagar, etc.)
                ];

                // Acumular totales para cajas (solo para resumen interno)
                $totalesGlobalesCajas['total_ingresos_cajas'] += $ingresosCaja;
                $totalesGlobalesCajas['total_egresos_cajas'] += $egresosCaja;
                $totalesGlobalesCajas['balance_global_cajas'] += $balanceCaja;
            }

            // ============== DISTRIBUCIÓN POR CAJA (PARA GRÁFICOS INTERNO) ==============
            $distribucionCajasPorBalance = collect($estadoPorCaja)->map(function ($item) use ($estadoPorCaja, $totalesGlobalesCajas) {
                $totalCajas = count($estadoPorCaja);
                $balanceGlobal = $totalesGlobalesCajas['balance_global_cajas'];
                return [
                    'caja_id' => $item['caja_operativa']['id'],
                    'nombre_caja' => $item['caja_operativa']['nombre'],
                    'balance_periodo' => $item['periodo']['balance_periodo'],
                    'porcentaje_balance' => $totalCajas > 0 && $balanceGlobal != 0
                        ? round(($item['periodo']['balance_periodo'] / $balanceGlobal) * 100, 2)
                        : 0,
                ];
            });

            // ============== TRANSACCIONES POR ESTADO (GLOBAL, INCLUYENDO REGLA) ==============
            // Aplicar lógica global: Ingresos sin caja + todos los egresos
            $transaccionesPorEstado = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    // Todos los egresos
                    $query->where('tipo_de_transaccion', 'Egreso')
                        // O ingresos sin caja operativa
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    'transaction_states.descripcion as estado_descripcion',
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy(
                    'transaction_states.id',
                    'transaction_states.nombre',
                    'transaction_states.descripcion',
                    'financial_transactions.tipo_de_transaccion'
                )
                ->get();

            // Organizar por estado
            $estadosFinancieros = [];
            foreach ($transaccionesPorEstado as $transaccion) {
                $estadoId = $transaccion->estado_id;
                $estadoNombre = strtoupper($transaccion->estado_nombre);
                $tipo = $transaccion->tipo_de_transaccion;

                if (!isset($estadosFinancieros[$estadoId])) {
                    $estadosFinancieros[$estadoId] = [
                        'estado_id' => $estadoId,
                        'estado_nombre' => $estadoNombre,
                        'estado_descripcion' => $transaccion->estado_descripcion,
                        'ingresos' => 0,
                        'egresos' => 0,
                        'total_transacciones_ingresos' => 0,
                        'total_transacciones_egresos' => 0,
                        'balance_estado' => 0
                    ];
                }

                if ($tipo === 'Ingreso') {
                    $estadosFinancieros[$estadoId]['ingresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                } else {
                    $estadosFinancieros[$estadoId]['egresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                }

                $estadosFinancieros[$estadoId]['balance_estado'] =
                    $estadosFinancieros[$estadoId]['ingresos'] - $estadosFinancieros[$estadoId]['egresos'];
            }

            // ============== DISTRIBUCIÓN POR ESTADO (PARA GRÁFICO GLOBAL) ==============
            $distribucionEstadosPorCantidad = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre')
                ->get();

            $totalTransacciones = $distribucionEstadosPorCantidad->sum('cantidad');
            $totalImporte = $distribucionEstadosPorCantidad->sum('total_importe');

            $distribucionEstadosPorCantidad = $distribucionEstadosPorCantidad->map(function ($item) use ($totalTransacciones, $totalImporte) {
                return [
                    'estado_id' => $item->estado_id,
                    'estado_nombre' => strtoupper($item->estado_nombre),
                    'cantidad' => $item->cantidad,
                    'total_importe' => floatval($item->total_importe),
                    'porcentaje_cantidad' => $totalTransacciones > 0
                        ? round(($item->cantidad / $totalTransacciones) * 100, 2)
                        : 0,
                    'porcentaje_importe' => $totalImporte > 0
                        ? round(($item->total_importe / $totalImporte) * 100, 2)
                        : 0
                ];
            });

            // ============== RESUMEN POR CATEGORÍA (GLOBAL) ==============
            $resumenPorCategoria = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->leftJoin('categories', 'financial_transactions.categoria_id', '=', 'categories.id')
                ->select(
                    DB::raw('COALESCE(categories.nombre, "Sin Categoría") as categoria'),
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('categories.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get()
                ->groupBy('categoria')
                ->map(function ($items, $categoria) {
                    $categoriaData = [
                        'categoria' => $categoria,
                        'total_ingresos' => 0,
                        'total_egresos' => 0,
                        'cantidad_ingresos' => 0,
                        'cantidad_egresos' => 0
                    ];

                    foreach ($items as $item) {
                        if ($item->tipo_de_transaccion === 'Ingreso') {
                            $categoriaData['total_ingresos'] += $item->total;
                            $categoriaData['cantidad_ingresos'] += $item->cantidad;
                        } else {
                            $categoriaData['total_egresos'] += $item->total;
                            $categoriaData['cantidad_egresos'] += $item->cantidad;
                        }
                    }

                    $categoriaData['balance_categoria'] = $categoriaData['total_ingresos'] - $categoriaData['total_egresos'];
                    return $categoriaData;
                });

            // ============== PREPARAR RESPUESTA ==============
            // Formato para campos monetarios
            $formatoMoneda = function ($valor) {
                return number_format($valor, 2, '.', ',');
            };

            $response = [
                'status' => 'success',
                'message' => 'Estado financiero global generado exitosamente',
                'datos' => [
                    'negocio' => [
                        'id' => $negocioId,
                        'nombre' => strtoupper($negocio->nombre)
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                    ],
                    // RESUMEN GLOBAL DEL NEGOCIO (principal)
                    'resumen_financiero' => [
                        'total_ingresos_brutos' => $formatoMoneda($totalIngresosBrutos),
                        'total_ingresos_brutos_raw' => floatval($totalIngresosBrutos),
                        'total_egresos_brutos' => $formatoMoneda($totalEgresosBrutos),
                        'total_egresos_brutos_raw' => floatval($totalEgresosBrutos),
                        'margen_bruto' => $formatoMoneda($margenBruto),
                        'margen_bruto_raw' => floatval($margenBruto),
                        'margen_util_antes_impuestos' => $formatoMoneda($margenUtilAntesImpuestos),
                        'margen_util_antes_impuestos_raw' => floatval($margenUtilAntesImpuestos),
                        'impuestos_estimados' => $formatoMoneda($impuestosEstimados),
                        'costos_fijos_adicionales' => $formatoMoneda($costosFijosAdicionales),
                        'rentabilidad_porcentaje' => number_format($rentabilidadPorcentaje, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidadPorcentaje),
                    ],
                    // RESUMEN DE CAJAS OPERATIVAS (secundario, para auditoría)
                    'resumen_global_cajas' => [
                        'total_ingresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_ingresos_cajas']),
                        'total_ingresos_cajas_raw' => $totalesGlobalesCajas['total_ingresos_cajas'],
                        'total_egresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_egresos_cajas']),
                        'total_egresos_cajas_raw' => $totalesGlobalesCajas['total_egresos_cajas'],
                        'balance_global_cajas' => $formatoMoneda($totalesGlobalesCajas['balance_global_cajas']),
                        'balance_global_cajas_raw' => $totalesGlobalesCajas['balance_global_cajas'],
                        'total_cajas_activas' => count($cajasOperativas),
                    ],
                    'detalle_por_caja' => array_values($estadoPorCaja), // Incluye ahora detalle_por_estado por caja
                    'distribucion_cajas' => [
                        'por_balance' => $distribucionCajasPorBalance->values()->toArray(),
                        'por_ingresos' => $distribucionCajasPorBalance->sortByDesc('balance_periodo')->values()->toArray(),
                    ],
                    // DETALLES GLOBALES
                    'detalle_por_estado' => array_values($estadosFinancieros),
                    'distribucion_estados' => [
                        'por_cantidad' => $distribucionEstadosPorCantidad->toArray(),
                        'por_importe' => $distribucionEstadosPorCantidad->sortByDesc('total_importe')->values()->toArray()
                    ],
                    'resumen_por_categoria' => $resumenPorCategoria->values()->all(),
                    'estadisticas_adicionales' => [
                        'total_transacciones' => $transaccionesPorEstado->sum('total_transacciones'),
                        'total_transacciones_ingresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'),
                        'total_transacciones_egresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'),
                        'total_transacciones_cajas' => collect($estadoPorCaja)->sum('transacciones_totales.total_transacciones_caja'),
                        'promedio_ingreso_transaccion' => $totalIngresosBrutos > 0 &&
                            $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones') > 0
                            ? $formatoMoneda($totalIngresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'))
                            : '0.00',
                        'promedio_egreso_transaccion' => $totalEgresosBrutos > 0 &&
                            $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones') > 0
                            ? $formatoMoneda($totalEgresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'))
                            : '0.00',
                        'promedio_balance_por_caja' => count($estadoPorCaja) > 0 ? round($totalesGlobalesCajas['balance_global_cajas'] / count($estadoPorCaja), 2) : 0,
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            Log::info('Estado financiero global generado exitosamente', [
                'negocio_id' => $negocioId,
                'total_ingresos_brutos' => $totalIngresosBrutos,
                'total_egresos_brutos' => $totalEgresosBrutos,
                'margen_bruto' => $margenBruto,
                'balance_global_cajas' => $totalesGlobalesCajas['balance_global_cajas'],
                'total_cajas' => count($cajasOperativas)
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al generar estado financiero global', [
                'negocio_id' => $negocioId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el estado financiero global',
                'error' => $e->getMessage()
            ], 500);
        }
    }




    /**
     * Obtener estados de resultados por rango de fechas
     *
     * REGLA DE NEGOCIO:
     * - INGRESOS: Solo se cuentan los que NO tienen caja_operativa_id (evita duplicar movimientos internos)
     * - EGRESOS: Se cuentan TODOS (incluye los de caja operativa, son gastos reales)
     */
    /*     public function getFinancialStatementByDateRange(Request $request): JsonResponse
    {
        // ============== VALIDACIÓN DE PARÁMETROS ==============
        $validator = Validator::make($request->all(), [
            'negocio_id' => ['required', 'exists:businesses,id'],
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ], [
            'negocio_id.required' => 'El ID del negocio es obligatorio',
            'negocio_id.exists' => 'El negocio seleccionado no existe',
            'fecha_inicial.required' => 'La fecha inicial es obligatoria',
            'fecha_inicial.date' => 'La fecha inicial debe ser una fecha válida',
            'fecha_final.required' => 'La fecha final es obligatoria',
            'fecha_final.date' => 'La fecha final debe ser una fecha válida',
            'fecha_final.after_or_equal' => 'La fecha final debe ser posterior o igual a la fecha inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        $negocioId = $request->negocio_id;
        $fechaInicial = $request->fecha_inicial;
        $fechaFinal = $request->fecha_final;

        try {
            // ============== INFORMACIÓN DEL NEGOCIO ==============
            $negocio = Business::findOrFail($negocioId);
            Log::info('Procesando estado financiero para negocio', [
                'negocio_id' => $negocioId,
                'negocio_nombre' => $negocio->nombre,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CALCULAR INGRESOS (SIN CAJA OPERATIVA) ==============
            $totalIngresosBrutos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            Log::info('Total ingresos brutos calculado', [
                'negocio_id' => $negocioId,
                'total_ingresos_brutos' => $totalIngresosBrutos,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CALCULAR EGRESOS (TODOS, INCLUYENDO CAJA OPERATIVA) ==============
            $totalEgresosBrutos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                // NO SE EXCLUYE caja_operativa_id - Se incluyen TODOS los egresos
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            Log::info('Total egresos brutos calculado', [
                'negocio_id' => $negocioId,
                'total_egresos_brutos' => $totalEgresosBrutos,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CALCULAR MÁRGENES ==============
            $margenBruto = $totalIngresosBrutos - $totalEgresosBrutos;

            // Margen Util Antes de Impuestos: Agregamos deducciones reales (ej. impuestos estimados 30% + costos fijos de $500)
            $impuestosEstimados = $margenBruto > 0 ? ($margenBruto * 0.30) : 0; // 30% ISR estimado
            $costosFijosAdicionales = 500; // Ejemplo: depreciación, salarios, etc. (ajusta según tu lógica)
            $margenUtilAntesImpuestos = $margenBruto - $impuestosEstimados - $costosFijosAdicionales;

            $rentabilidadPorcentaje = $totalIngresosBrutos > 0
                ? ($margenUtilAntesImpuestos / $totalIngresosBrutos) * 100 // Usamos margen util para rentabilidad
                : 0;

            Log::info('Márgenes calculados', [
                'negocio_id' => $negocioId,
                'margen_bruto' => $margenBruto,
                'margen_util_antes_impuestos' => $margenUtilAntesImpuestos,
                'impuestos_estimados' => $impuestosEstimados,
                'rentabilidad_porcentaje' => $rentabilidadPorcentaje
            ]);

            // ============== TRANSACCIONES POR ESTADO ==============
            // Aplicar misma lógica: Ingresos sin caja operativa, Egresos con todo
            $transaccionesPorEstado = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    // Incluir egresos (todos)
                    $query->where('tipo_de_transaccion', 'Egreso')
                        // O ingresos sin caja operativa
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    'transaction_states.descripcion as estado_descripcion',
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy(
                    'transaction_states.id',
                    'transaction_states.nombre',
                    'transaction_states.descripcion',
                    'financial_transactions.tipo_de_transaccion'
                )
                ->get();

            // ============== ORGANIZAR DATOS POR ESTADO ==============
            $estadosFinancieros = [];
            foreach ($transaccionesPorEstado as $transaccion) {
                $estadoId = $transaccion->estado_id;
                $estadoNombre = strtoupper($transaccion->estado_nombre);
                $tipo = $transaccion->tipo_de_transaccion;

                if (!isset($estadosFinancieros[$estadoId])) {
                    $estadosFinancieros[$estadoId] = [
                        'estado_id' => $estadoId,
                        'estado_nombre' => $estadoNombre,
                        'estado_descripcion' => $transaccion->estado_descripcion,
                        'ingresos' => 0,
                        'egresos' => 0,
                        'total_transacciones_ingresos' => 0,
                        'total_transacciones_egresos' => 0,
                        'balance_estado' => 0
                    ];
                }

                if ($tipo === 'Ingreso') {
                    $estadosFinancieros[$estadoId]['ingresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                } else {
                    $estadosFinancieros[$estadoId]['egresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                }

                $estadosFinancieros[$estadoId]['balance_estado'] =
                    $estadosFinancieros[$estadoId]['ingresos'] - $estadosFinancieros[$estadoId]['egresos'];
            }

            // ============== DISTRIBUCIÓN POR ESTADO (PARA GRÁFICO DE TORTA) ==============
            $distribucionEstadosPorCantidad = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre')
                ->get();

            $totalTransacciones = $distribucionEstadosPorCantidad->sum('cantidad');
            $totalImporte = $distribucionEstadosPorCantidad->sum('total_importe');

            $distribucionEstadosPorCantidad = $distribucionEstadosPorCantidad->map(function ($item) use ($totalTransacciones, $totalImporte) {
                return [
                    'estado_id' => $item->estado_id,
                    'estado_nombre' => strtoupper($item->estado_nombre),
                    'cantidad' => $item->cantidad,
                    'total_importe' => floatval($item->total_importe),
                    'porcentaje_cantidad' => $totalTransacciones > 0
                        ? round(($item->cantidad / $totalTransacciones) * 100, 2)
                        : 0,
                    'porcentaje_importe' => $totalImporte > 0
                        ? round(($item->total_importe / $totalImporte) * 100, 2)
                        : 0
                ];
            });

            // ============== RESUMEN POR CATEGORÍA ==============
            $resumenPorCategoria = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->leftJoin('categories', 'financial_transactions.categoria_id', '=', 'categories.id')
                ->select(
                    DB::raw('COALESCE(categories.nombre, "Sin Categoría") as categoria'),
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('categories.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get()
                ->groupBy('categoria')
                ->map(function ($items, $categoria) {
                    $categoriaData = [
                        'categoria' => $categoria,
                        'total_ingresos' => 0,
                        'total_egresos' => 0,
                        'cantidad_ingresos' => 0,
                        'cantidad_egresos' => 0
                    ];

                    foreach ($items as $item) {
                        if ($item->tipo_de_transaccion === 'Ingreso') {
                            $categoriaData['total_ingresos'] += $item->total;
                            $categoriaData['cantidad_ingresos'] += $item->cantidad;
                        } else {
                            $categoriaData['total_egresos'] += $item->total;
                            $categoriaData['cantidad_egresos'] += $item->cantidad;
                        }
                    }

                    $categoriaData['balance_categoria'] = $categoriaData['total_ingresos'] - $categoriaData['total_egresos'];
                    return $categoriaData;
                });

            // ============== PREPARAR RESPUESTA ==============
            // Formato para los campos clave (con separadores de miles y 2 decimales)
            $formatoMoneda = function ($valor) {
                return number_format($valor, 2, '.', ',');
            };

            $response = [
                'status' => 'success',
                'message' => 'Estado financiero generado exitosamente',
                'datos' => [
                    'negocio' => [
                        'id' => $negocioId,
                        'nombre' => strtoupper($negocio->nombre)
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                    ],
                    // RESUMEN FINANCIERO - Campos clave al inicio y destacados
                    'resumen_financiero' => [
                        // CAMPOS SOLICITADOS - Formato legible
                        'total_ingresos_brutos' => $formatoMoneda($totalIngresosBrutos),
                        'total_ingresos_brutos_raw' => floatval($totalIngresosBrutos), // Para cálculos frontend
                        'total_egresos_brutos' => $formatoMoneda($totalEgresosBrutos),
                        'total_egresos_brutos_raw' => floatval($totalEgresosBrutos), // Para cálculos frontend
                        'margen_bruto' => $formatoMoneda($margenBruto),
                        'margen_bruto_raw' => floatval($margenBruto), // Para cálculos frontend
                        'margen_util_antes_impuestos' => $formatoMoneda($margenUtilAntesImpuestos),
                        'margen_util_antes_impuestos_raw' => floatval($margenUtilAntesImpuestos), // Para cálculos frontend
                        'impuestos_estimados' => $formatoMoneda($impuestosEstimados), // Detalle adicional
                        'costos_fijos_adicionales' => $formatoMoneda($costosFijosAdicionales), // Detalle adicional
                        'rentabilidad_porcentaje' => number_format($rentabilidadPorcentaje, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidadPorcentaje), // Para gráficos
                    ],
                    'detalle_por_estado' => array_values($estadosFinancieros),
                    'distribucion_estados' => [
                        'por_cantidad' => $distribucionEstadosPorCantidad->toArray(),
                        'por_importe' => $distribucionEstadosPorCantidad->sortByDesc('total_importe')->values()->toArray()
                    ],
                    'resumen_por_categoria' => $resumenPorCategoria->values()->all(),
                    'estadisticas_adicionales' => [
                        'total_transacciones' => $transaccionesPorEstado->sum('total_transacciones'),
                        'total_transacciones_ingresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'),
                        'total_transacciones_egresos' => $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'),
                        'promedio_ingreso_transaccion' => $totalIngresosBrutos > 0 &&
                            $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones') > 0
                            ? $formatoMoneda($totalIngresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Ingreso')->sum('total_transacciones'))
                            : '0.00',
                        'promedio_egreso_transaccion' => $totalEgresosBrutos > 0 &&
                            $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones') > 0
                            ? $formatoMoneda($totalEgresosBrutos / $transaccionesPorEstado->filter(fn($t) => $t->tipo_de_transaccion === 'Egreso')->sum('total_transacciones'))
                            : '0.00'
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            Log::info('Estado financiero generado exitosamente', [
                'negocio_id' => $negocioId,
                'total_ingresos_brutos' => $totalIngresosBrutos,
                'total_egresos_brutos' => $totalEgresosBrutos,
                'margen_bruto' => $margenBruto,
                'margen_util_antes_impuestos' => $margenUtilAntesImpuestos
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al generar estado financiero', [
                'negocio_id' => $negocioId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    } */


    /**
     * Exportar estado financiero a Excel (actualizado para usar los nuevos campos)
     */
    public function exportToExcel(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Reutiliza getFinancialStatementByDateRange para consistencia
            $response = $this->getFinancialStatementByDateRange($request);
            $responseData = $response->getData(true);

            if ($responseData['status'] !== 'success') {
                throw new \Exception($responseData['message'] ?? 'Error al obtener datos');
            }

            $export = new FinancialStatementExport($responseData['datos'], $request->all());

            $negocio = Business::findOrFail($request->negocio_id);
            $nombreArchivo = 'Estado_Financiero_' .
                str_replace(' ', '_', strtoupper($negocio->nombre)) . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.xlsx';

            return Excel::download($export, $nombreArchivo);
        } catch (\Exception $e) {
            Log::error('Error al exportar a Excel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Exportar estado financiero a PDF (actualizado para incluir nuevos campos)
     */
    public function exportToPDF(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $response = $this->getFinancialStatementByDateRange($request);
            $responseData = $response->getData(true);

            if ($responseData['status'] !== 'success') {
                throw new \Exception('Error al obtener datos financieros');
            }

            $data = $responseData['datos'];

            $pdf = Pdf::loadView('exports.financial_statement_pdf', ['data' => $data]);
            $pdf->setPaper('A4', 'portrait');

            $negocio = Business::findOrFail($request->negocio_id);
            $nombreArchivo = 'Estado_Financiero_' .
                str_replace(' ', '_', strtoupper($negocio->nombre)) . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.pdf';

            return $pdf->download($nombreArchivo);
        } catch (\Exception $e) {
            Log::error('Error al exportar a PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
