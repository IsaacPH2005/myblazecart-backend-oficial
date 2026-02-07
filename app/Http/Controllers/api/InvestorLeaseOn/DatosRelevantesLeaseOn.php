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
    public function getMyBusinesses(Request $request)
    {
        try {
            $userId = Auth::id();

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

    public function getMyVehiclesByBusiness(Request $request)
    {
        try {
            $request->validate([
                'negocio_id' => 'required|exists:businesses,id'
            ]);

            $userId = Auth::id();

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

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds);

            $diasTranscurridos = $fechaInicial->diffInDays($fechaFinal) + 1;

            $ingresosBrutos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->sum('importe_total');

            $millasRecorridas = $query->clone()
                ->whereNotNull('millas')
                ->sum('millas');

            $productividadPorDia = $diasTranscurridos > 0
                ? round($ingresosBrutos / $diasTranscurridos, 2)
                : 0;

            $cargaMejorPagada = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->orderBy('importe_total', 'desc')
                ->first();

            $estimacionPagoPorMilla = $millasRecorridas > 0
                ? round($ingresosBrutos / $millasRecorridas, 2)
                : 0;

            $numeroCargas = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->count();

            $promedioPorCarga = $numeroCargas > 0
                ? round($ingresosBrutos / $numeroCargas, 2)
                : 0;

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
                        'item' => 'INGRESOS BRUTOS',
                        'total' => number_format($ingresosBrutos, 2, '.', ','),
                        'unidad' => 'USD',
                        'valor_numerico' => $ingresosBrutos,
                        'icono' => '📈'
                    ],
                    [
                        'ranking' => 2,
                        'item' => 'MILLAS RECORRIDAS',
                        'total' => number_format($millasRecorridas, 0, '.', ','),
                        'unidad' => 'millas',
                        'valor_numerico' => $millasRecorridas,
                        'icono' => '🛣️'
                    ],
                    [
                        'ranking' => 3,
                        'item' => 'PRODUCTIVIDAD POR DÍA',
                        'total' => number_format($productividadPorDia, 2, '.', ','),
                        'unidad' => '$/día',
                        'valor_numerico' => $productividadPorDia,
                        'icono' => '📊'
                    ],
                    [
                        'ranking' => 4,
                        'item' => 'NÚMERO DE CARGAS',
                        'total' => $numeroCargas,
                        'unidad' => 'cargas',
                        'icono' => '📦'
                    ],
                    [
                        'ranking' => 5,
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
                        'ranking' => 6,
                        'item' => 'PAGO POR MILLA',
                        'total' => number_format($estimacionPagoPorMilla, 2, '.', ','),
                        'unidad' => '$/milla',
                        'valor_numerico' => $estimacionPagoPorMilla,
                        'icono' => '🎯'
                    ],
                    [
                        'ranking' => 7,
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

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds);

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

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->whereIn('vehicle_id', $misVehiculosIds)
                ->where('tipo_de_transaccion', 'ingreso');

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
                        ]
                    ]
                ];
            }

            usort($comparativa, function ($a, $b) {
                return $b['metricas']['valores_numericos']['ingresos'] <=>
                    $a['metricas']['valores_numericos']['ingresos'];
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
