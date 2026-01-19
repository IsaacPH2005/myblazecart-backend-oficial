<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Exports\FinancialStatementExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\OperatingBox;
use App\Models\Vehicle;
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
     * ============================================================================
     * ESTADO FINANCIERO GLOBAL CON FILTRO OPCIONAL POR VEHÍCULO
     * ============================================================================
     *
     * Este método maneja DOS casos:
     * 1. Estado financiero GLOBAL del negocio (cuando NO se envía vehicle_id)
     * 2. Estado financiero POR VEHÍCULO (cuando SÍ se envía vehicle_id)
     *
     * PARÁMETROS:
     * - negocio_id: ID del negocio (obligatorio)
     * - vehicle_id: ID del vehículo (opcional - para filtrar por vehículo)
     * - fecha_inicial: Fecha de inicio (obligatorio)
     * - fecha_final: Fecha de fin (obligatorio)
     *
     * REGLA DE NEGOCIO:
     * - INGRESOS: Solo los que NO tienen caja_operativa_id
     * - EGRESOS: TODOS (incluye los de caja operativa)
     * - CATEGORÍAS: Solo se muestran las que tienen movimiento (transacciones > 0 y monto > 0)
     */
    public function getFinancialStatementByDateRange(Request $request): JsonResponse
    {
        // ============== VALIDACIÓN DE PARÁMETROS ==============
        $validator = Validator::make($request->all(), [
            'negocio_id' => ['required', 'exists:businesses,id'],
            'vehicle_id' => ['nullable', 'exists:vehicles,id'], // OPCIONAL: filtro por vehículo
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ], [
            'negocio_id.required' => 'El ID del negocio es obligatorio',
            'negocio_id.exists' => 'El negocio seleccionado no existe',
            'vehicle_id.exists' => 'El vehículo seleccionado no existe',
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
        $vehicleId = $request->vehicle_id; // PUEDE SER NULL
        $fechaInicial = $request->fecha_inicial;
        $fechaFinal = $request->fecha_final;

        try {
            // ============== INFORMACIÓN DEL NEGOCIO ==============
            $negocio = Business::findOrFail($negocioId);

            // ============== INFORMACIÓN DEL VEHÍCULO (SI SE FILTRA) ==============
            $vehicle = null;
            $esFiltradoPorVehiculo = !is_null($vehicleId);

            if ($esFiltradoPorVehiculo) {
                $vehicle = Vehicle::findOrFail($vehicleId);

                // Verificar que el vehículo pertenece al negocio
                if ($vehicle->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }
            }

            Log::info('Procesando estado financiero', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'filtrado_por_vehiculo' => $esFiltradoPorVehiculo,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CALCULAR TOTALES (CON O SIN FILTRO DE VEHÍCULO) ==============
            // Query base para ingresos
            $queryIngresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            // Query base para egresos
            $queryEgresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            // Si hay filtro por vehículo, aplicarlo a ambas queries
            if ($esFiltradoPorVehiculo) {
                $queryIngresos->where('vehicle_id', $vehicleId);
                $queryEgresos->where('vehicle_id', $vehicleId);
            }

            $totalIngresosBrutos = $queryIngresos->sum('importe_total');
            $totalEgresosBrutos = $queryEgresos->sum('importe_total');

            $margenBruto = $totalIngresosBrutos - $totalEgresosBrutos;
            $margenUtilAntesImpuestos = 0;
            $impuestosEstimados = 0;
            $costosFijosAdicionales = 0;

            $rentabilidadPorcentaje = $totalIngresosBrutos > 0
                ? ($margenBruto / $totalIngresosBrutos) * 100
                : 0;

            // ============== CAJAS OPERATIVAS (SOLO SI NO HAY FILTRO DE VEHÍCULO) ==============
            $estadoPorCaja = [];
            $totalesGlobalesCajas = [
                'total_ingresos_cajas' => 0,
                'total_egresos_cajas' => 0,
                'balance_global_cajas' => 0
            ];
            $distribucionCajasPorBalance = collect([]);

            // Solo calcular cajas si NO hay filtro de vehículo
            if (!$esFiltradoPorVehiculo) {
                $cajasConTransacciones = FinancialTransactions::where('negocio_id', $negocioId)
                    ->whereNotNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->select('caja_operativa_id')
                    ->distinct()
                    ->pluck('caja_operativa_id');

                $cajasOperativas = OperatingBox::whereIn('id', $cajasConTransacciones)
                    ->where('estado', true)
                    ->get();

                foreach ($cajasOperativas as $caja) {
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

                    // Detalle por estado para esta caja
                    $transaccionesPorEstadoCaja = FinancialTransactions::where('negocio_id', $negocioId)
                        ->where('caja_operativa_id', $caja->id)
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
                                'ingresos_recargas' => 0,
                                'egresos_subtracciones' => 0,
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

                    $promedioIngreso = $totalTransaccionesIngresos > 0 ? $ingresosCaja / $totalTransaccionesIngresos : 0;
                    $promedioEgreso = $totalTransaccionesEgresos > 0 ? $egresosCaja / $totalTransaccionesEgresos : 0;

                    $estadoPorCaja[] = [
                        'caja_operativa' => [
                            'id' => $caja->id,
                            'nombre' => strtoupper($caja->nombre),
                            'descripcion' => $caja->descripcion ?? 'Sin descripción',
                            'saldo_actual' => floatval($caja->saldo),
                        ],
                        'periodo' => [
                            'ingresos_recargas' => floatval($ingresosCaja),
                            'egresos_subtracciones' => floatval($egresosCaja),
                            'balance_periodo' => floatval($balanceCaja),
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
                        'rentabilidad_caja' => $ingresosCaja > 0 ? round((($ingresosCaja - $egresosCaja) / $ingresosCaja) * 100, 2) : 0,
                        'diferencia_saldo' => floatval($caja->saldo - $balanceCaja),
                        'detalle_por_estado' => array_values($estadosPorCaja),
                    ];

                    $totalesGlobalesCajas['total_ingresos_cajas'] += $ingresosCaja;
                    $totalesGlobalesCajas['total_egresos_cajas'] += $egresosCaja;
                    $totalesGlobalesCajas['balance_global_cajas'] += $balanceCaja;
                }

                // Distribución por caja
                $distribucionCajasPorBalance = collect($estadoPorCaja)->map(function ($item) use ($totalesGlobalesCajas) {
                    $balanceGlobal = $totalesGlobalesCajas['balance_global_cajas'];
                    return [
                        'caja_id' => $item['caja_operativa']['id'],
                        'nombre_caja' => $item['caja_operativa']['nombre'],
                        'balance_periodo' => $item['periodo']['balance_periodo'],
                        'porcentaje_balance' => $balanceGlobal != 0
                            ? round(($item['periodo']['balance_periodo'] / $balanceGlobal) * 100, 2)
                            : 0,
                    ];
                });
            }

            // ============== TRANSACCIONES POR ESTADO ==============
            $queryTransaccionesEstado = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            // Aplicar filtro de vehículo si existe
            if ($esFiltradoPorVehiculo) {
                $queryTransaccionesEstado->where('vehicle_id', $vehicleId);
            }

            $transaccionesPorEstado = $queryTransaccionesEstado
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

            // ============== DISTRIBUCIÓN POR ESTADO ==============
            $queryDistribucion = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryDistribucion->where('vehicle_id', $vehicleId);
            }

            $distribucionEstadosPorCantidad = $queryDistribucion
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

            // ============== RESUMEN POR CATEGORÍA (FILTRADO) ==============
            $queryCategoria = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryCategoria->where('vehicle_id', $vehicleId);
            }

            $resumenPorCategoria = $queryCategoria
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
                })
                // ⭐ FILTRAR: Solo categorías con movimiento
                ->filter(function ($categoria) {
                    $totalIngresos = floatval($categoria['total_ingresos']);
                    $totalEgresos = floatval($categoria['total_egresos']);
                    $cantidadTotal = intval($categoria['cantidad_ingresos']) + intval($categoria['cantidad_egresos']);

                    // Solo incluir si tiene transacciones Y (tiene ingresos O tiene egresos)
                    return $cantidadTotal > 0 && ($totalIngresos > 0 || $totalEgresos > 0);
                });

            // ⭐ FILTRAR ESTADÍSTICAS ADICIONALES: categoria_mayor y categoria_menor
            $categoriaMayorEgreso = null;
            $categoriaMenorEgreso = null;

            if ($resumenPorCategoria->isNotEmpty()) {
                // Filtrar solo categorías con egresos > 0
                $categoriasConEgresos = $resumenPorCategoria->filter(function ($cat) {
                    return floatval($cat['total_egresos']) > 0;
                });

                if ($categoriasConEgresos->isNotEmpty()) {
                    // Mayor egreso
                    $categoriaMayorEgreso = $categoriasConEgresos->sortByDesc('total_egresos')->first();

                    // Menor egreso (pero mayor a 0)
                    $categoriaMenorEgreso = $categoriasConEgresos->sortBy('total_egresos')->first();
                }
            }

            // ============== PREPARAR RESPUESTA ==============
            $formatoMoneda = function ($valor) {
                return number_format($valor, 2, '.', ',');
            };

            $responseData = [
                'negocio' => [
                    'id' => $negocioId,
                    'nombre' => strtoupper($negocio->nombre)
                ],
                'periodo' => [
                    'fecha_inicial' => $fechaInicial,
                    'fecha_final' => $fechaFinal,
                    'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                ],
                'filtro' => [
                    'por_vehiculo' => $esFiltradoPorVehiculo,
                    'vehicle_id' => $vehicleId,
                ],
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
                        : '0.00',
                    // ⭐ AGREGAR: estadísticas filtradas
                    'categoria_mayor_egreso' => $categoriaMayorEgreso ? [
                        'categoria' => $categoriaMayorEgreso['categoria'],
                        'total_egresos' => floatval($categoriaMayorEgreso['total_egresos']),
                        'cantidad_transacciones' => intval($categoriaMayorEgreso['cantidad_egresos'])
                    ] : null,
                    'categoria_menor_egreso' => $categoriaMenorEgreso ? [
                        'categoria' => $categoriaMenorEgreso['categoria'],
                        'total_egresos' => floatval($categoriaMenorEgreso['total_egresos']),
                        'cantidad_transacciones' => intval($categoriaMenorEgreso['cantidad_egresos'])
                    ] : null,
                ]
            ];

            // Si hay filtro por vehículo, agregar información del vehículo
            if ($esFiltradoPorVehiculo && $vehicle) {
                $responseData['vehiculo'] = [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehicle->tipo_propiedad),
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                ];
            }

            // Si NO hay filtro por vehículo, agregar datos de cajas operativas
            if (!$esFiltradoPorVehiculo) {
                $responseData['resumen_global_cajas'] = [
                    'total_ingresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_ingresos_cajas']),
                    'total_ingresos_cajas_raw' => $totalesGlobalesCajas['total_ingresos_cajas'],
                    'total_egresos_cajas' => $formatoMoneda($totalesGlobalesCajas['total_egresos_cajas']),
                    'total_egresos_cajas_raw' => $totalesGlobalesCajas['total_egresos_cajas'],
                    'balance_global_cajas' => $formatoMoneda($totalesGlobalesCajas['balance_global_cajas']),
                    'balance_global_cajas_raw' => $totalesGlobalesCajas['balance_global_cajas'],
                    'total_cajas_activas' => count($estadoPorCaja),
                ];
                $responseData['detalle_por_caja'] = array_values($estadoPorCaja);
                $responseData['distribucion_cajas'] = [
                    'por_balance' => $distribucionCajasPorBalance->values()->toArray(),
                    'por_ingresos' => $distribucionCajasPorBalance->sortByDesc('balance_periodo')->values()->toArray(),
                ];
                $responseData['estadisticas_adicionales']['total_transacciones_cajas'] = collect($estadoPorCaja)->sum('transacciones_totales.total_transacciones_caja');
                $responseData['estadisticas_adicionales']['promedio_balance_por_caja'] = count($estadoPorCaja) > 0
                    ? round($totalesGlobalesCajas['balance_global_cajas'] / count($estadoPorCaja), 2)
                    : 0;
            }

            $response = [
                'status' => 'success',
                'message' => $esFiltradoPorVehiculo
                    ? 'Estado financiero del vehículo generado exitosamente'
                    : 'Estado financiero global generado exitosamente',
                'datos' => $responseData,
                'timestamp' => now()->toDateTimeString()
            ];

            Log::info('Estado financiero generado exitosamente', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'filtrado_por_vehiculo' => $esFiltradoPorVehiculo,
                'total_ingresos_brutos' => $totalIngresosBrutos,
                'total_egresos_brutos' => $totalEgresosBrutos,
                'margen_bruto' => $margenBruto,
                'categorias_con_movimiento' => $resumenPorCategoria->count(),
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al generar estado financiero', [
                'negocio_id' => $negocioId ?? null,
                'vehicle_id' => $vehicleId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function getVehiclesByBusiness(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
            ], [
                'negocio_id.required' => 'El ID del negocio es obligatorio',
                'negocio_id.exists' => 'El negocio seleccionado no existe',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $negocioId = $request->input('negocio_id');

            // Primero verificar si hay vehículos SIN el filtro de is_active
            $totalVehicles = Vehicle::where('negocio_id', $negocioId)->count();
            $activeVehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->count();

            // Obtener vehículos activos
            $vehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData'])
                ->orderBy('tipo_propiedad')
                ->orderBy('codigo_unico')
                ->get();

            if ($vehicles->isEmpty()) {
                // Si no hay vehículos activos, intentar obtener todos
                $allVehicles = Vehicle::where('negocio_id', $negocioId)->get();

                return response()->json([
                    'status' => 'success',
                    'message' => 'No hay vehículos activos para este negocio',
                    'datos' => [],
                    'total' => 0,
                    'debug' => [
                        'total_vehiculos_db' => $totalVehicles,
                        'vehiculos_activos' => $activeVehicles,
                        'vehiculos_inactivos' => $allVehicles->pluck('codigo_unico')
                    ]
                ], 200);
            }

            $vehiculosData = $vehicles->map(function ($vehicle) {
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
                }

                return [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'numero_vin' => $vehicle->numero_vin,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'color' => $vehicle->color,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehicle->tipo_propiedad),
                    'usuario_asignado' => $assignedUserName,
                    'usuario_asignado_id' => $vehicle->user_id,
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                    'millaje' => intval($vehicle->millaje ?? 0),
                    'is_active' => $vehicle->estado,
                    'nombre_display' => trim("{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})")
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Vehículos obtenidos correctamente',
                'datos' => $vehiculosData->toArray(),
                'total' => $vehiculosData->count(),
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los vehículos',
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
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
