<?php

namespace App\Http\Controllers\api\InvestorLeaseOn;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use App\Models\Business;
use App\Models\Vehicle;
use App\Models\Investment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class DatosRelevantesLeaseOn extends Controller
{
    /**
     * Obtener lista de negocios donde el inversionista tiene inversión
     */
    public function getMyBusinesses(Request $request)
    {
        try {
            $userId = Auth::id();

            // Obtener negocios únicos donde el inversionista tiene vehículos
            $negocios = Investment::with('vehicle.negocio')
                ->where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true)
                ->whereHas('vehicle.negocio')
                ->get()
                ->pluck('vehicle.negocio')
                ->unique('id')
                ->values()
                ->map(function ($negocio) {
                    return [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                        'descripcion' => $negocio->descripcion,
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Mis negocios obtenidos correctamente',
                'data' => $negocios,
                'total' => $negocios->count(),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tus negocios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener vehículos donde el inversionista tiene inversión en un negocio
     */
    public function getMyVehiclesByBusiness(Request $request)
    {
        try {
            $request->validate([
                'negocio_id' => 'required|exists:businesses,id'
            ]);

            $userId = Auth::id();

            // Obtener vehículos del negocio donde el usuario tiene inversión
            $vehiculos = Investment::with(['vehicle' => function ($query) {
                $query->where('estado', true)
                    ->orderBy('marca')
                    ->orderBy('modelo');
            }])
                ->where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true)
                ->whereHas('vehicle', function ($query) use ($request) {
                    $query->where('negocio_id', $request->negocio_id);
                })
                ->get()
                ->map(function ($inversion) {
                    $vehicle = $inversion->vehicle;
                    return [
                        'id' => $vehicle->id,
                        'codigo_unico' => $vehicle->codigo_unico,
                        'marca' => $vehicle->marca,
                        'modelo' => $vehicle->modelo,
                        'año' => $vehicle->año,
                        'numero_placa' => $vehicle->numero_placa,
                        'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                        'tipo_propiedad' => $vehicle->tipo_propiedad,
                        'nombre_display' => "{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})",
                        'mi_inversion' => [
                            'monto' => number_format($inversion->monto_inversion, 2, '.', ','),
                            'monto_raw' => $inversion->monto_inversion,
                            'descripcion' => $inversion->descripcion,
                            'estado' => $inversion->estado,
                        ],
                    ];
                });

            return response()->json([
                'success' => true,
                'message' => 'Tus vehículos obtenidos correctamente',
                'data' => $vehiculos,
                'total' => $vehiculos->count(),
                'timestamp' => now()->format('Y-m-d H:i:s')
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tus vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener datos relevantes de operación del inversionista
     * Filtrable por negocio y/o vehículo
     */
    public function getMyOperationReport(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $userId = Auth::id();
            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            // Obtener IDs de vehículos donde el inversionista tiene inversión
            $vehiculosQuery = Investment::where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true);

            if ($request->negocio_id) {
                $vehiculosQuery->whereHas('vehicle', function ($query) use ($request) {
                    $query->where('negocio_id', $request->negocio_id);
                });
            }

            if ($request->vehicle_id) {
                $vehiculosQuery->where('vehicle_id', $request->vehicle_id);
            }

            $misVehiculosIds = $vehiculosQuery->pluck('vehicle_id')->toArray();

            if (empty($misVehiculosIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones en vehículos para los filtros seleccionados'
                ], 404);
            }

            // Información del negocio y vehículo
            $negocioInfo = null;
            $vehiculoInfo = null;

            if ($request->negocio_id) {
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
                $vehiculo = Vehicle::with('negocio')->find($request->vehicle_id);
                if ($vehiculo) {
                    $vehiculoInfo = [
                        'id' => $vehiculo->id,
                        'codigo_unico' => $vehiculo->codigo_unico,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'numero_placa' => $vehiculo->numero_placa,
                        'nombre_completo' => "{$vehiculo->marca} {$vehiculo->modelo} ({$vehiculo->numero_placa})",
                    ];

                    if (!$request->negocio_id && $vehiculo->negocio) {
                        $negocioInfo = [
                            'id' => $vehiculo->negocio->id,
                            'nombre' => $vehiculo->negocio->nombre,
                            'descripcion' => $vehiculo->negocio->descripcion,
                        ];
                    }
                }
            }

            // Query base de transacciones (SOLO de mis vehículos)
            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds);

            // Calcular días transcurridos
            $diasTranscurridos = $fechaInicial->diffInDays($fechaFinal) + 1;

            // INGRESOS BRUTOS
            $ingresosBrutos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->sum('importe_total');

            // EGRESOS BRUTOS
            $egresosBrutos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->sum('importe_total');

            // MARGEN BRUTO
            $margenBruto = $ingresosBrutos - $egresosBrutos;

            // RENTABILIDAD %
            $rentabilidadPorcentaje = $ingresosBrutos > 0
                ? round(($margenBruto / $ingresosBrutos) * 100, 2)
                : 0;

            // MILLAS RECORRIDAS
            $millasRecorridas = $query->clone()
                ->whereNotNull('millas')
                ->sum('millas');

            // PRODUCTIVIDAD POR DÍA
            $productividadPorDia = $diasTranscurridos > 0
                ? round($ingresosBrutos / $diasTranscurridos, 2)
                : 0;

            // CARGA MEJOR PAGADA
            $cargaMejorPagada = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->orderBy('importe_total', 'desc')
                ->first();

            // ESTIMACIÓN PAGO POR MILLA
            $estimacionPagoPorMilla = $millasRecorridas > 0
                ? round($ingresosBrutos / $millasRecorridas, 2)
                : 0;

            // NÚMERO DE CARGAS
            $numeroCargas = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->count();

            // PROMEDIO POR CARGA
            $promedioPorCarga = $numeroCargas > 0
                ? round($ingresosBrutos / $numeroCargas, 2)
                : 0;

            // MI INVERSIÓN TOTAL
            $miInversionTotal = Investment::where('user_id', $userId)
                ->whereIn('vehicle_id', $misVehiculosIds)
                ->where('estado', 'activo')
                ->where('active', true)
                ->sum('monto_inversion');

            // ROI DEL INVERSIONISTA
            $miROI = $miInversionTotal > 0
                ? round(($margenBruto / $miInversionTotal) * 100, 2)
                : 0;

            // CANTIDAD DE VEHÍCULOS CON INVERSIÓN
            $cantidadVehiculosInvertidos = count($misVehiculosIds);

            // Preparar datos de respuesta
            $datosOperacion = [
                'filtros_aplicados' => [
                    'negocio' => $negocioInfo,
                    'vehiculo' => $vehiculoInfo,
                ],
                'mi_inversion' => [
                    'monto_total' => number_format($miInversionTotal, 2, '.', ','),
                    'monto_total_raw' => $miInversionTotal,
                    'cantidad_vehiculos' => $cantidadVehiculosInvertidos,
                    'roi_porcentaje' => number_format($miROI, 2, '.', ','),
                    'roi_porcentaje_raw' => $miROI,
                    'ganancia_estimada' => number_format($margenBruto, 2, '.', ','),
                    'ganancia_estimada_raw' => $margenBruto,
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
                        'item' => 'MI INVERSIÓN TOTAL',
                        'total' => number_format($miInversionTotal, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $miInversionTotal,
                        'icono' => '💰'
                    ],
                    [
                        'ranking' => 3,
                        'item' => 'VEHÍCULOS CON INVERSIÓN',
                        'total' => $cantidadVehiculosInvertidos,
                        'unidad' => 'vehículos',
                        'icono' => '🚗'
                    ],
                    [
                        'ranking' => 4,
                        'item' => 'INGRESOS BRUTOS',
                        'total' => number_format($ingresosBrutos, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $ingresosBrutos,
                        'icono' => '📈'
                    ],
                    [
                        'ranking' => 5,
                        'item' => 'EGRESOS BRUTOS',
                        'total' => number_format($egresosBrutos, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $egresosBrutos,
                        'icono' => '💸'
                    ],
                    [
                        'ranking' => 6,
                        'item' => 'MARGEN BRUTO',
                        'total' => number_format($margenBruto, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $margenBruto,
                        'icono' => '📊'
                    ],
                    [
                        'ranking' => 7,
                        'item' => 'MI ROI',
                        'total' => number_format($miROI, 2, '.', ','),
                        'unidad' => '%',
                        'valor_numerico' => $miROI,
                        'icono' => '🎯'
                    ],
                    [
                        'ranking' => 8,
                        'item' => 'RENTABILIDAD DEL NEGOCIO',
                        'total' => number_format($rentabilidadPorcentaje, 2, '.', ','),
                        'unidad' => '%',
                        'valor_numerico' => $rentabilidadPorcentaje,
                        'icono' => '💹'
                    ],
                    [
                        'ranking' => 9,
                        'item' => 'MILLAS RECORRIDAS',
                        'total' => number_format($millasRecorridas, 0, '.', ','),
                        'unidad' => 'millas',
                        'valor_numerico' => $millasRecorridas,
                        'icono' => '🛣️'
                    ],
                    [
                        'ranking' => 10,
                        'item' => 'PRODUCTIVIDAD POR DÍA',
                        'total' => number_format($productividadPorDia, 2, '.', ','),
                        'unidad' => '$/día',
                        'valor_numerico' => $productividadPorDia,
                        'icono' => '📅'
                    ],
                    [
                        'ranking' => 11,
                        'item' => 'NÚMERO DE CARGAS',
                        'total' => $numeroCargas,
                        'unidad' => 'cargas',
                        'icono' => '📦'
                    ],
                    [
                        'ranking' => 12,
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
                        'ranking' => 13,
                        'item' => 'PAGO POR MILLA',
                        'total' => number_format($estimacionPagoPorMilla, 2, '.', ','),
                        'unidad' => '$/milla',
                        'valor_numerico' => $estimacionPagoPorMilla,
                        'icono' => '🎯'
                    ],
                    [
                        'ranking' => 14,
                        'item' => 'PROMEDIO POR CARGA',
                        'total' => number_format($promedioPorCarga, 2, '.', ','),
                        'unidad' => '$/carga',
                        'valor_numerico' => $promedioPorCarga,
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
                'message' => 'Error al generar tu reporte de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen estadístico del inversionista
     */
    public function getMyOperationSummary(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $userId = Auth::id();
            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            // Obtener vehículos del inversionista
            $vehiculosQuery = Investment::where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true);

            if ($request->negocio_id) {
                $vehiculosQuery->whereHas('vehicle', function ($query) use ($request) {
                    $query->where('negocio_id', $request->negocio_id);
                });
            }

            if ($request->vehicle_id) {
                $vehiculosQuery->where('vehicle_id', $request->vehicle_id);
            }

            $misVehiculosIds = $vehiculosQuery->pluck('vehicle_id')->toArray();

            if (empty($misVehiculosIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones para los filtros seleccionados'
                ], 404);
            }

            // Query base
            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds);

            // Totales
            $totalIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->sum('importe_total');

            $totalEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->sum('importe_total');

            $numeroIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->count();

            $numeroEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->count();

            $balanceNeto = $totalIngresos - $totalEgresos;

            $promedioIngresos = $numeroIngresos > 0
                ? round($totalIngresos / $numeroIngresos, 2)
                : 0;

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

            // Top clientes
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

            // Distribución por tipo
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
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar tu resumen de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener productividad diaria del inversionista
     */
    public function getMyDailyProductivity(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $userId = Auth::id();
            $fechaInicial = Carbon::parse($request->fecha_inicial);
            $fechaFinal = Carbon::parse($request->fecha_final);

            // Obtener vehículos del inversionista
            $vehiculosQuery = Investment::where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true);

            if ($request->negocio_id) {
                $vehiculosQuery->whereHas('vehicle', function ($query) use ($request) {
                    $query->where('negocio_id', $request->negocio_id);
                });
            }

            if ($request->vehicle_id) {
                $vehiculosQuery->where('vehicle_id', $request->vehicle_id);
            }

            $misVehiculosIds = $vehiculosQuery->pluck('vehicle_id')->toArray();

            if (empty($misVehiculosIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones para los filtros seleccionados'
                ], 404);
            }

            // Query base
            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds)
                ->where('tipo_de_transaccion', 'ingreso');

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

            // Estadísticas
            $valoresNumericos = $productividadDiaria->pluck('valores_numericos.productividad');
            $promedioProductividad = $valoresNumericos->avg();
            $maxProductividad = $valoresNumericos->max();
            $minProductividad = $valoresNumericos->min();
            $totalProductividad = $valoresNumericos->sum();

            $valoresMillas = $productividadDiaria->pluck('valores_numericos.millas');
            $totalMillas = $valoresMillas->sum();
            $totalTransacciones = $productividadDiaria->sum('transacciones');

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
                'message' => 'Error al generar tu productividad diaria',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comparativa entre mis vehículos
     */
    public function compareMyVehicles(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $userId = Auth::id();
            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            // Obtener inversiones del usuario
            $inversionesQuery = Investment::with('vehicle.negocio')
                ->where('user_id', $userId)
                ->where('estado', 'activo')
                ->where('active', true);

            if ($request->negocio_id) {
                $inversionesQuery->whereHas('vehicle', function ($query) use ($request) {
                    $query->where('negocio_id', $request->negocio_id);
                });
            }

            $inversiones = $inversionesQuery->get();

            if ($inversiones->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones para comparar'
                ], 404);
            }

            $comparativa = [];

            foreach ($inversiones as $inversion) {
                $vehiculo = $inversion->vehicle;

                $query = FinancialTransactions::query()
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
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

                $margen = $ingresos - $egresos;
                $miROI = $inversion->monto_inversion > 0
                    ? round(($margen / $inversion->monto_inversion) * 100, 2)
                    : 0;

                $comparativa[] = [
                    'vehiculo_id' => $vehiculo->id,
                    'vehiculo_info' => [
                        'codigo_unico' => $vehiculo->codigo_unico,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'numero_placa' => $vehiculo->numero_placa,
                        'tipo_propiedad' => $vehiculo->tipo_propiedad,
                        'nombre_completo' => "{$vehiculo->marca} {$vehiculo->modelo} ({$vehiculo->numero_placa})",
                        'negocio' => $vehiculo->negocio ? [
                            'id' => $vehiculo->negocio->id,
                            'nombre' => $vehiculo->negocio->nombre,
                        ] : null,
                    ],
                    'mi_inversion' => [
                        'monto' => number_format($inversion->monto_inversion, 2, '.', ','),
                        'monto_raw' => $inversion->monto_inversion,
                        'descripcion' => $inversion->descripcion,
                        'roi_porcentaje' => number_format($miROI, 2, '.', ','),
                        'roi_porcentaje_raw' => $miROI,
                    ],
                    'metricas' => [
                        'ingresos' => number_format($ingresos, 2, '.', ','),
                        'egresos' => number_format($egresos, 2, '.', ','),
                        'margen' => number_format($margen, 2, '.', ','),
                        'millas' => number_format($millas, 0, '.', ','),
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
                            'margen' => round($margen, 2),
                            'roi' => $miROI,
                        ]
                    ]
                ];
            }

            // Ordenar por ROI descendente
            usort($comparativa, function ($a, $b) {
                return $b['metricas']['valores_numericos']['roi'] <=>
                    $a['metricas']['valores_numericos']['roi'];
            });

            return response()->json([
                'success' => true,
                'data' => [
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
                'message' => 'Error al generar comparativa de tus vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
