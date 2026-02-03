<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\Business;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatosRelevantesController extends Controller
{
    /**
     * Obtener lista de vehículos por negocio
     */
    public function getVehiclesByBusiness(Request $request)
    {
        try {
            $request->validate([
                'negocio_id' => 'required|exists:businesses,id'
            ]);

            $negocio = Business::with(['vehicles' => function ($query) {
                $query->where('estado', true)
                    ->orderBy('marca')
                    ->orderBy('modelo');
            }])->findOrFail($request->negocio_id);

            $vehiculos = $negocio->vehicles->map(function ($vehicle) {
                return [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'numero_placa' => $vehicle->numero_placa,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'nombre_display' => "{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})",
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Vehículos obtenidos correctamente',
                'datos' => $vehiculos,
                'total' => $vehiculos->count(),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener vehículos del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener datos relevantes de la operación
     * Filtrable por negocio y/o vehículo
     */
    public function getOperationReport(Request $request)
    {
        try {
            // Validar fechas de entrada
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            // Query base con filtros opcionales
            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true); // Solo transacciones activas

            // Información del negocio y vehículo para contexto
            $negocioInfo = null;
            $vehiculoInfo = null;

            // Filtros opcionales
            if ($request->negocio_id) {
                $query->where('negocio_id', $request->negocio_id);

                $negocio = Business::find($request->negocio_id);
                if ($negocio) {
                    $negocioInfo = [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                        'descripcion' => $negocio->descripcion,
                    ];
                }
            }

            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);

                $vehiculo = Vehicle::with('negocio')->find($request->vehicle_id);
                if ($vehiculo) {
                    $vehiculoInfo = [
                        'id' => $vehiculo->id,
                        'codigo_unico' => $vehiculo->codigo_unico,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'año' => $vehiculo->año,
                        'numero_placa' => $vehiculo->numero_placa,
                        'nombre_completo' => "{$vehiculo->marca} {$vehiculo->modelo} ({$vehiculo->numero_placa})",
                        'negocio' => $vehiculo->negocio ? [
                            'id' => $vehiculo->negocio->id,
                            'nombre' => $vehiculo->negocio->nombre,
                        ] : null
                    ];

                    // Si se filtró por vehículo pero no por negocio, agregar el negocio automáticamente
                    if (!$request->negocio_id && $vehiculo->negocio) {
                        $negocioInfo = [
                            'id' => $vehiculo->negocio->id,
                            'nombre' => $vehiculo->negocio->nombre,
                            'descripcion' => $vehiculo->negocio->descripcion,
                        ];
                    }
                }
            }

            // Calcular días transcurridos
            $diasTranscurridos = $fechaInicial->diffInDays($fechaFinal) + 1;

            // ✅ INGRESOS BRUTOS (Total de ingresos SIN excluir caja operativa)
            $ingresosBrutos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->sum('importe_total');

            // ✅ EGRESOS BRUTOS (Total de egresos)
            $egresosBrutos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->sum('importe_total');

            // ✅ MARGEN BRUTO (Ingresos - Egresos)
            $margenBruto = $ingresosBrutos - $egresosBrutos;

            // ✅ RENTABILIDAD % ((Margen / Ingresos) * 100)
            $rentabilidadPorcentaje = $ingresosBrutos > 0
                ? round(($margenBruto / $ingresosBrutos) * 100, 2)
                : 0;

            // Calcular millas recorridas en servicio
            $millasRecorridas = $query->clone()
                ->whereNotNull('millas')
                ->sum('millas');

            // Calcular productividad por día (basado en ingresos brutos)
            $productividadPorDia = $diasTranscurridos > 0
                ? round($ingresosBrutos / $diasTranscurridos, 2)
                : 0;

            // Calcular carga mejor pagada (transacción con mayor importe)
            $cargaMejorPagada = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id') // Solo cargas reales (no recargas)
                ->orderBy('importe_total', 'desc')
                ->first();

            // Calcular estimación de pago por milla
            $estimacionPagoPorMilla = $millasRecorridas > 0
                ? round($ingresosBrutos / $millasRecorridas, 2)
                : 0;

            // Contar número total de cargas (ingresos sin caja operativa)
            $numeroCargas = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->count();

            // Preparar datos de respuesta
            $datosOperacion = [
                'filtros_aplicados' => [
                    'negocio' => $negocioInfo,
                    'vehiculo' => $vehiculoInfo,
                ],
                'periodo' => [
                    'fecha_inicial' => $fechaInicial->format('d/m/Y'),
                    'fecha_final' => $fechaFinal->format('d/m/Y'),
                    'dias_transcurridos' => $diasTranscurridos
                ],
                'datos_relevantes' => [
                    [
                        'ranking' => 1,
                        'item' => 'DÍAS TRANSCURRIDOS',
                        'total' => $diasTranscurridos,
                        'unidad' => 'días',
                        'icono' => '📅'
                    ],
                    [
                        'ranking' => 2,
                        'item' => 'INGRESOS BRUTOS',
                        'total' => number_format($ingresosBrutos, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $ingresosBrutos,
                        'icono' => '💰'
                    ],
                    [
                        'ranking' => 3,
                        'item' => 'EGRESOS BRUTOS',
                        'total' => number_format($egresosBrutos, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $egresosBrutos,
                        'icono' => '💸'
                    ],
                    [
                        'ranking' => 4,
                        'item' => 'MARGEN BRUTO',
                        'total' => number_format($margenBruto, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $margenBruto,
                        'icono' => '📊'
                    ],
                    [
                        'ranking' => 5,
                        'item' => 'RENTABILIDAD %',
                        'total' => number_format($rentabilidadPorcentaje, 2, '.', ','),
                        'unidad' => '%',
                        'valor_numerico' => $rentabilidadPorcentaje,
                        'icono' => '📈'
                    ],
                    [
                        'ranking' => 6,
                        'item' => 'MILLAS RECORRIDAS EN SERVICIO',
                        'total' => number_format($millasRecorridas, 2, '.', ','),
                        'unidad' => 'millas',
                        'valor_numerico' => $millasRecorridas,
                        'icono' => '🚚'
                    ],
                    [
                        'ranking' => 7,
                        'item' => 'PRODUCTIVIDAD POR DÍA',
                        'total' => number_format($productividadPorDia, 2, '.', ','),
                        'unidad' => '$/día',
                        'valor_numerico' => $productividadPorDia,
                        'icono' => '📅'
                    ],
                    [
                        'ranking' => 8,
                        'item' => 'NÚMERO DE CARGAS',
                        'total' => $numeroCargas,
                        'unidad' => 'cargas',
                        'icono' => '📦'
                    ],
                    [
                        'ranking' => 9,
                        'item' => 'CARGA MEJOR PAGADA',
                        'total' => $cargaMejorPagada
                            ? number_format($cargaMejorPagada->importe_total, 2, '.', ',')
                            : '0.00',
                        'unidad' => 'USD',
                        'valor_numerico' => $cargaMejorPagada ? $cargaMejorPagada->importe_total : 0,
                        'detalle' => $cargaMejorPagada ? [
                            'cliente' => $cargaMejorPagada->cliente_proveedor ?? 'Sin cliente',
                            'fecha' => Carbon::parse($cargaMejorPagada->fecha)->format('d/m/Y'),
                            'destino' => $cargaMejorPagada->destino ?? 'Sin destino',
                            'millas' => $cargaMejorPagada->millas ?? 0
                        ] : null,
                        'icono' => '🏆'
                    ],
                    [
                        'ranking' => 10,
                        'item' => 'ESTIMACIÓN PAGO POR MILLA',
                        'total' => number_format($estimacionPagoPorMilla, 2, '.', ','),
                        'unidad' => '$/milla',
                        'valor_numerico' => $estimacionPagoPorMilla,
                        'icono' => '🎯'
                    ],
                    [
                        'ranking' => 11,
                        'item' => 'PROMEDIO POR CARGA',
                        'total' => $numeroCargas > 0
                            ? number_format($ingresosBrutos / $numeroCargas, 2, '.', ',')
                            : '0.00',
                        'unidad' => '$/carga',
                        'valor_numerico' => $numeroCargas > 0
                            ? round($ingresosBrutos / $numeroCargas, 2)
                            : 0,
                        'icono' => '💵'
                    ]
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $datosOperacion
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen estadístico adicional
     * Filtrable por negocio y/o vehículo
     */
    public function getOperationSummary(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true);

            // Aplicar filtros
            if ($request->negocio_id) {
                $query->where('negocio_id', $request->negocio_id);
            }

            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            // Totales por tipo de transacción
            $totalIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->sum('importe_total');

            $totalEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->sum('importe_total');

            // Número de transacciones
            $numeroIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->count();

            $numeroEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->count();

            // Balance neto
            $balanceNeto = $totalIngresos - $totalEgresos;

            // Promedio de ingresos por transacción
            $promedioIngresos = $numeroIngresos > 0
                ? round($totalIngresos / $numeroIngresos, 2)
                : 0;

            // Promedio de egresos por transacción
            $promedioEgresos = $numeroEgresos > 0
                ? round($totalEgresos / $numeroEgresos, 2)
                : 0;

            // Top categorías de gastos
            $topCategoriasGastos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->with('categoria')
                ->select('categoria_id', DB::raw('SUM(importe_total) as total'), DB::raw('COUNT(*) as cantidad'))
                ->groupBy('categoria_id')
                ->orderBy('total', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'categoria' => $item->categoria->nombre ?? 'Sin categoría',
                        'total' => number_format($item->total, 2, '.', ','),
                        'cantidad' => $item->cantidad,
                        'valor_numerico' => round($item->total, 2)
                    ];
                });

            // Top clientes por productividad
            $topClientes = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->whereNotNull('cliente_proveedor')
                ->select(
                    'cliente_proveedor',
                    DB::raw('COUNT(*) as numero_cargas'),
                    DB::raw('SUM(importe_total) as total_ingreso'),
                    DB::raw('SUM(millas) as total_millas')
                )
                ->groupBy('cliente_proveedor')
                ->orderBy('total_ingreso', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'cliente' => $item->cliente_proveedor,
                        'cargas' => $item->numero_cargas,
                        'total' => number_format($item->total_ingreso, 2, '.', ','),
                        'millas' => round($item->total_millas, 2),
                        'promedio_por_carga' => $item->numero_cargas > 0
                            ? number_format($item->total_ingreso / $item->numero_cargas, 2, '.', ',')
                            : '0.00',
                        'valor_numerico' => round($item->total_ingreso, 2)
                    ];
                });

            // Resumen por método de pago
            $resumenMetodosPago = $query->clone()
                ->with('metodo')
                ->select(
                    'metodo_id',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('metodo_id')
                ->orderBy('total', 'desc')
                ->get()
                ->map(function ($item) {
                    return [
                        'metodo' => $item->metodo->nombre ?? 'Sin método',
                        'cantidad' => $item->cantidad,
                        'total' => number_format($item->total, 2, '.', ','),
                        'valor_numerico' => round($item->total, 2)
                    ];
                });

            // Distribución por tipo de transacción
            $distribucionTipos = [
                [
                    'tipo' => 'Ingresos',
                    'cantidad' => $numeroIngresos,
                    'total' => number_format($totalIngresos, 2, '.', ','),
                    'promedio' => number_format($promedioIngresos, 2, '.', ','),
                    'porcentaje' => ($totalIngresos + $totalEgresos) > 0
                        ? round(($totalIngresos / ($totalIngresos + $totalEgresos)) * 100, 2)
                        : 0,
                    'color' => '#10B981',
                    'icono' => '📈'
                ],
                [
                    'tipo' => 'Egresos',
                    'cantidad' => $numeroEgresos,
                    'total' => number_format($totalEgresos, 2, '.', ','),
                    'promedio' => number_format($promedioEgresos, 2, '.', ','),
                    'porcentaje' => ($totalIngresos + $totalEgresos) > 0
                        ? round(($totalEgresos / ($totalIngresos + $totalEgresos)) * 100, 2)
                        : 0,
                    'color' => '#EF4444',
                    'icono' => '📉'
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2, '.', ','),
                        'total_egresos' => number_format($totalEgresos, 2, '.', ','),
                        'balance_neto' => number_format($balanceNeto, 2, '.', ','),
                        'numero_ingresos' => $numeroIngresos,
                        'numero_egresos' => $numeroEgresos,
                        'promedio_ingresos' => number_format($promedioIngresos, 2, '.', ','),
                        'promedio_egresos' => number_format($promedioEgresos, 2, '.', ','),
                        'valores_numericos' => [
                            'total_ingresos' => $totalIngresos,
                            'total_egresos' => $totalEgresos,
                            'balance_neto' => $balanceNeto,
                        ]
                    ],
                    'distribucion_tipos' => $distribucionTipos,
                    'top_categorias_gastos' => $topCategoriasGastos,
                    'top_clientes' => $topClientes,
                    'metodos_pago' => $resumenMetodosPago
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el resumen de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener gráfico de productividad diaria
     * Filtrable por negocio y/o vehículo
     */
    public function getDailyProductivity(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial);
            $fechaFinal = Carbon::parse($request->fecha_final);

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true)
                ->where('tipo_de_transaccion', 'ingreso');

            // Aplicar filtros
            if ($request->negocio_id) {
                $query->where('negocio_id', $request->negocio_id);
            }

            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            // Agrupar por día
            $productividadDiaria = $query
                ->select(
                    DB::raw('DATE(fecha) as dia'),
                    DB::raw('SUM(importe_total) as total_dia'),
                    DB::raw('COUNT(*) as numero_transacciones'),
                    DB::raw('SUM(millas) as millas_dia')
                )
                ->groupBy('dia')
                ->orderBy('dia')
                ->get()
                ->map(function ($item) {
                    $promedioPorTransaccion = $item->numero_transacciones > 0
                        ? round($item->total_dia / $item->numero_transacciones, 2)
                        : 0;

                    $promedioPorMilla = $item->millas_dia > 0
                        ? round($item->total_dia / $item->millas_dia, 2)
                        : 0;

                    return [
                        'fecha' => Carbon::parse($item->dia)->format('d/m/Y'),
                        'fecha_iso' => Carbon::parse($item->dia)->format('Y-m-d'),
                        'dia_semana' => Carbon::parse($item->dia)->locale('es')->dayName,
                        'productividad' => number_format($item->total_dia, 2, '.', ','),
                        'transacciones' => $item->numero_transacciones,
                        'millas' => number_format($item->millas_dia, 2, '.', ','),
                        'promedio_por_transaccion' => number_format($promedioPorTransaccion, 2, '.', ','),
                        'promedio_por_milla' => number_format($promedioPorMilla, 2, '.', ','),
                        'valores_numericos' => [
                            'productividad' => round($item->total_dia, 2),
                            'millas' => round($item->millas_dia, 2),
                            'promedio_transaccion' => $promedioPorTransaccion,
                            'promedio_milla' => $promedioPorMilla
                        ]
                    ];
                });

            // Calcular estadísticas (valores numéricos)
            $valoresNumericos = $productividadDiaria->pluck('valores_numericos.productividad');
            $promedioProductividad = $valoresNumericos->avg();
            $maxProductividad = $valoresNumericos->max();
            $minProductividad = $valoresNumericos->min();
            $totalProductividad = $valoresNumericos->sum();

            $valoresMillas = $productividadDiaria->pluck('valores_numericos.millas');
            $totalMillas = $valoresMillas->sum();

            $totalTransacciones = $productividadDiaria->sum('transacciones');

            // Encontrar mejor y peor día
            $mejorDia = $productividadDiaria->sortByDesc('valores_numericos.productividad')->first();
            $peorDia = $productividadDiaria
                ->where('valores_numericos.productividad', '>', 0)
                ->sortBy('valores_numericos.productividad')
                ->first();

            return response()->json([
                'success' => true,
                'data' => [
                    'productividad_diaria' => $productividadDiaria->values(),
                    'estadisticas' => [
                        'promedio_diario' => number_format($promedioProductividad, 2, '.', ','),
                        'maximo' => number_format($maxProductividad, 2, '.', ','),
                        'minimo' => number_format($minProductividad, 2, '.', ','),
                        'total' => number_format($totalProductividad, 2, '.', ','),
                        'total_millas' => number_format($totalMillas, 2, '.', ','),
                        'total_transacciones' => $totalTransacciones,
                        'dias_totales' => $productividadDiaria->count(),
                        'dias_con_actividad' => $productividadDiaria->where('valores_numericos.productividad', '>', 0)->count(),
                        'mejor_dia' => $mejorDia,
                        'peor_dia' => $peorDia
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte de productividad diaria',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comparativa entre vehículos de un negocio
     */
    public function compareVehicles(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'negocio_id' => 'required|exists:businesses,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            $negocio = Business::with('vehicles')->findOrFail($request->negocio_id);

            $comparativa = [];

            foreach ($negocio->vehicles as $vehiculo) {
                $query = FinancialTransactions::query()
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->where('estado', true)
                    ->where('vehicle_id', $vehiculo->id);

                $ingresos = $query->clone()
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->sum('importe_total');

                $egresos = $query->clone()
                    ->where('tipo_de_transaccion', 'egreso')
                    ->sum('importe_total');

                $millas = $query->clone()
                    ->whereNotNull('millas')
                    ->sum('millas');

                $numeroCargas = $query->clone()
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->whereNull('caja_operativa_id')
                    ->count();

                $comparativa[] = [
                    'vehiculo_id' => $vehiculo->id,
                    'vehiculo_info' => [
                        'codigo_unico' => $vehiculo->codigo_unico,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'numero_placa' => $vehiculo->numero_placa,
                        'nombre_completo' => "{$vehiculo->marca} {$vehiculo->modelo} ({$vehiculo->numero_placa})",
                    ],
                    'metricas' => [
                        'ingresos' => number_format($ingresos, 2, '.', ','),
                        'egresos' => number_format($egresos, 2, '.', ','),
                        'balance' => number_format($ingresos - $egresos, 2, '.', ','),
                        'millas' => number_format($millas, 2, '.', ','),
                        'numero_cargas' => $numeroCargas,
                        'promedio_por_carga' => $numeroCargas > 0
                            ? number_format($ingresos / $numeroCargas, 2, '.', ',')
                            : '0.00',
                        'pago_por_milla' => $millas > 0
                            ? number_format($ingresos / $millas, 2, '.', ',')
                            : '0.00',
                        'valores_numericos' => [
                            'ingresos' => round($ingresos, 2),
                            'egresos' => round($egresos, 2),
                            'balance' => round($ingresos - $egresos, 2)
                        ]
                    ]
                ];
            }

            // Ordenar por ingresos descendente
            usort($comparativa, function ($a, $b) {
                return $b['metricas']['valores_numericos']['ingresos'] <=>
                    $a['metricas']['valores_numericos']['ingresos'];
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial->format('d/m/Y'),
                        'fecha_final' => $fechaFinal->format('d/m/Y'),
                    ],
                    'comparativa_vehiculos' => $comparativa,
                    'total_vehiculos' => count($comparativa)
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar la comparativa de vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar reporte a Excel
     */
    public function exportToExcel(Request $request)
    {
        try {
            // Primero obtener los datos del reporte
            $reportData = $this->getOperationReport($request);
            $data = json_decode($reportData->getContent(), true);

            if (!$data['success']) {
                throw new \Exception('Error al generar datos del reporte');
            }

            // Aquí podrías usar Laravel Excel o PHPSpreadsheet para generar el archivo
            // Por ahora retorno los datos formateados

            return response()->json([
                'success' => true,
                'message' => 'Datos listos para exportar',
                'data' => $data['data']
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar el reporte',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
