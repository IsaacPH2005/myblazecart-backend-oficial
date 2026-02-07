<?php

namespace App\Http\Controllers\api\InvestorLeaseOn;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\FinancialTransactions;
use App\Models\Business;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InvestorDashboardLeaseOn extends Controller
{
    /**
     * Dashboard principal del INVERSIONISTA LEASE ON - Resumen general
     */
    public function index()
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('INVERSIONISTA LEASE ON')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a este dashboard'
                ], 403);
            }

            // Obtener todas las inversiones del usuario
            $investments = Investment::with(['vehicle.negocio', 'business'])
                ->where('user_id', $user->id)
                ->where('estado', 'activo')
                ->where('active', true)
                ->get();

            if ($investments->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No tienes inversiones registradas',
                    'inversionista' => [
                        'id' => $user->id,
                        'nombre_completo' => $user->generalData
                            ? $user->generalData->nombre . ' ' . $user->generalData->apellido
                            : 'Sin datos',
                        'email' => $user->email,
                    ],
                    'estadisticas' => [
                        'total_inversiones' => 0,
                        'capital_total' => 0,
                        'inversiones_activas' => 0,
                        'negocios_unicos' => 0,
                        'vehiculos_unicos' => 0,
                        'ingresos_totales' => 0,
                        'egresos_totales' => 0,
                        'utilidad_neta' => 0,
                        'roi_promedio' => 0,
                    ],
                    'inversiones_por_negocio' => [],
                    'top_vehiculos_rentables' => [],
                    'actividad_reciente' => [],
                ]);
            }

            // Estadísticas generales
            $totalInversiones = $investments->count();
            $capitalTotal = $investments->sum('monto_inversion');
            $inversionesActivas = $investments->where('active', true)->count();

            // IDs únicos
            $vehiculosIds = $investments->whereNotNull('vehicle_id')->pluck('vehicle_id')->unique();
            $negociosIds = $investments->map(function ($inv) {
                return $inv->vehicle ? $inv->vehicle->negocio_id : $inv->business_id;
            })->filter()->unique();

            $vehiculosUnicos = $vehiculosIds->count();
            $negociosUnicos = $negociosIds->count();

            // Calcular ingresos y egresos totales
            $ingresosVehiculos = FinancialTransactions::whereIn('vehicle_id', $vehiculosIds)
                ->where('tipo_de_transaccion', 'ingreso')
                ->where('estado', true)
                ->sum('importe_total');

            $egresosVehiculos = FinancialTransactions::whereIn('vehicle_id', $vehiculosIds)
                ->where('tipo_de_transaccion', 'egreso')
                ->where('estado', true)
                ->sum('importe_total');

            $utilidadNeta = $ingresosVehiculos - $egresosVehiculos;
            $roiPromedio = $capitalTotal > 0 ? ($utilidadNeta / $capitalTotal) * 100 : 0;

            // Inversiones por negocio
            $inversionesPorNegocio = [];
            foreach ($negociosIds as $negocioId) {
                $negocio = Business::find($negocioId);
                if (!$negocio) continue;

                $vehiculosDelNegocio = $investments->filter(function ($inv) use ($negocioId) {
                    return $inv->vehicle && $inv->vehicle->negocio_id == $negocioId;
                });

                $capitalNegocio = $vehiculosDelNegocio->sum('monto_inversion');
                $vehiculosIdsNegocio = $vehiculosDelNegocio->pluck('vehicle_id')->unique();

                $ingresosNegocio = FinancialTransactions::whereIn('vehicle_id', $vehiculosIdsNegocio)
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                $egresosNegocio = FinancialTransactions::whereIn('vehicle_id', $vehiculosIdsNegocio)
                    ->where('tipo_de_transaccion', 'egreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                $utilidadNegocio = $ingresosNegocio - $egresosNegocio;
                $roiNegocio = $capitalNegocio > 0 ? ($utilidadNegocio / $capitalNegocio) * 100 : 0;

                $inversionesPorNegocio[] = [
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                        'descripcion' => $negocio->descripcion,
                    ],
                    'capital_invertido' => round($capitalNegocio, 2),
                    'cantidad_vehiculos' => $vehiculosIdsNegocio->count(),
                    'rendimiento' => [
                        'ingresos' => round($ingresosNegocio, 2),
                        'egresos' => round($egresosNegocio, 2),
                        'utilidad' => round($utilidadNegocio, 2),
                        'roi_porcentaje' => round($roiNegocio, 2),
                    ],
                ];
            }

            // Top 5 vehículos más rentables
            $topVehiculosRentables = [];
            foreach ($vehiculosIds as $vehiculoId) {
                $investment = $investments->where('vehicle_id', $vehiculoId)->first();
                if (!$investment || !$investment->vehicle) continue;

                $ingresos = FinancialTransactions::where('vehicle_id', $vehiculoId)
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                $egresos = FinancialTransactions::where('vehicle_id', $vehiculoId)
                    ->where('tipo_de_transaccion', 'egreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                $utilidad = $ingresos - $egresos;
                $roi = $investment->monto_inversion > 0
                    ? ($utilidad / $investment->monto_inversion) * 100
                    : 0;

                $topVehiculosRentables[] = [
                    'vehiculo' => [
                        'id' => $investment->vehicle->id,
                        'codigo_unico' => $investment->vehicle->codigo_unico,
                        'numero_placa' => $investment->vehicle->numero_placa,
                        'marca' => $investment->vehicle->marca,
                        'modelo' => $investment->vehicle->modelo,
                    ],
                    'negocio' => $investment->vehicle->negocio ? [
                        'id' => $investment->vehicle->negocio->id,
                        'nombre' => $investment->vehicle->negocio->nombre,
                    ] : null,
                    'capital_invertido' => round($investment->monto_inversion, 2),
                    'rendimiento' => [
                        'ingresos' => round($ingresos, 2),
                        'egresos' => round($egresos, 2),
                        'utilidad' => round($utilidad, 2),
                        'roi_porcentaje' => round($roi, 2),
                    ],
                ];
            }

            // Ordenar por ROI descendente y tomar los top 5
            usort($topVehiculosRentables, function ($a, $b) {
                return $b['rendimiento']['roi_porcentaje'] <=> $a['rendimiento']['roi_porcentaje'];
            });
            $topVehiculosRentables = array_slice($topVehiculosRentables, 0, 5);

            // Actividad reciente
            $actividadReciente = FinancialTransactions::with(['vehicle.negocio', 'categoria'])
                ->whereIn('vehicle_id', $vehiculosIds)
                ->where('estado', true)
                ->orderBy('fecha', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'tipo' => ucfirst($tx->tipo_de_transaccion),
                        'vehiculo' => $tx->vehicle ? [
                            'codigo_unico' => $tx->vehicle->codigo_unico,
                            'numero_placa' => $tx->vehicle->numero_placa,
                        ] : null,
                        'negocio' => $tx->vehicle && $tx->vehicle->negocio ? [
                            'nombre' => $tx->vehicle->negocio->nombre,
                        ] : null,
                        'categoria' => $tx->categoria ? $tx->categoria->nombre : 'Sin categoría',
                        'monto' => round($tx->importe_total, 2),
                        'fecha' => Carbon::parse($tx->fecha)->format('d/m/Y'),
                        'fecha_relativa' => Carbon::parse($tx->fecha)->diffForHumans(),
                        'descripcion' => $tx->item ?? $tx->observaciones ?? 'Sin descripción',
                    ];
                });

            return response()->json([
                'success' => true,
                'inversionista' => [
                    'id' => $user->id,
                    'nombre_completo' => $user->generalData
                        ? $user->generalData->nombre . ' ' . $user->generalData->apellido
                        : 'Sin datos',
                    'email' => $user->email,
                    'celular' => $user->generalData?->celular ?? 'No especificado',
                    'ciudad' => $user->generalData?->ciudad ?? 'No especificada',
                ],
                'estadisticas' => [
                    'total_inversiones' => $totalInversiones,
                    'capital_total' => round($capitalTotal, 2),
                    'inversiones_activas' => $inversionesActivas,
                    'negocios_unicos' => $negociosUnicos,
                    'vehiculos_unicos' => $vehiculosUnicos,
                    'ingresos_totales' => round($ingresosVehiculos, 2),
                    'egresos_totales' => round($egresosVehiculos, 2),
                    'utilidad_neta' => round($utilidadNeta, 2),
                    'roi_promedio' => round($roiPromedio, 2),
                ],
                'inversiones_por_negocio' => $inversionesPorNegocio,
                'top_vehiculos_rentables' => $topVehiculosRentables,
                'actividad_reciente' => $actividadReciente,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener mis inversiones con detalles de rendimiento
     */
    public function myInvestments()
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('INVERSIONISTA LEASE ON')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            $investments = Investment::with(['vehicle.negocio', 'business'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($investment) {
                    $rendimiento = [
                        'ingresos' => 0,
                        'egresos' => 0,
                        'utilidad' => 0,
                        'roi_porcentaje' => 0,
                    ];

                    if ($investment->vehicle_id) {
                        $ingresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                            ->where('tipo_de_transaccion', 'ingreso')
                            ->where('estado', true)
                            ->sum('importe_total');

                        $egresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                            ->where('tipo_de_transaccion', 'egreso')
                            ->where('estado', true)
                            ->sum('importe_total');

                        $utilidad = $ingresos - $egresos;
                        $roi = $investment->monto_inversion > 0
                            ? ($utilidad / $investment->monto_inversion) * 100
                            : 0;

                        $rendimiento = [
                            'ingresos' => round($ingresos, 2),
                            'egresos' => round($egresos, 2),
                            'utilidad' => round($utilidad, 2),
                            'roi_porcentaje' => round($roi, 2),
                        ];
                    }

                    return [
                        'id' => $investment->id,
                        'vehiculo' => $investment->vehicle ? [
                            'id' => $investment->vehicle->id,
                            'codigo_unico' => $investment->vehicle->codigo_unico,
                            'numero_placa' => $investment->vehicle->numero_placa,
                            'marca' => $investment->vehicle->marca,
                            'modelo' => $investment->vehicle->modelo,
                            'año' => $investment->vehicle->año,
                            'tipo_vehiculo' => $investment->vehicle->tipo_vehiculo,
                            'tipo_propiedad' => $investment->vehicle->tipo_propiedad,
                        ] : null,
                        'negocio' => $investment->vehicle && $investment->vehicle->negocio ? [
                            'id' => $investment->vehicle->negocio->id,
                            'nombre' => $investment->vehicle->negocio->nombre,
                            'descripcion' => $investment->vehicle->negocio->descripcion,
                        ] : ($investment->business ? [
                            'id' => $investment->business->id,
                            'nombre' => $investment->business->nombre,
                            'descripcion' => $investment->business->descripcion,
                        ] : null),
                        'monto_inversion' => round($investment->monto_inversion, 2),
                        'descripcion' => $investment->descripcion ?? 'Sin descripción',
                        'notas' => $investment->notas ?? 'Sin notas',
                        'estado' => $investment->estado,
                        'active' => $investment->active,
                        'fecha_inversion' => $investment->created_at
                            ? $investment->created_at->format('d/m/Y')
                            : 'No especificada',
                        'rendimiento' => $rendimiento,
                    ];
                });

            $totalInvertido = $investments->sum('monto_inversion');
            $utilidadTotal = $investments->sum('rendimiento.utilidad');
            $roiPromedio = $totalInvertido > 0 ? ($utilidadTotal / $totalInvertido) * 100 : 0;

            return response()->json([
                'success' => true,
                'data' => $investments,
                'resumen' => [
                    'total_invertido' => round($totalInvertido, 2),
                    'utilidad_total' => round($utilidadTotal, 2),
                    'roi_promedio' => round($roiPromedio, 2),
                    'cantidad_inversiones' => $investments->count(),
                    'inversiones_activas' => $investments->where('active', true)->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar las inversiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalles de un vehículo específico donde tengo inversión
     */
    public function vehicleDetails($vehicleId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('INVERSIONISTA LEASE ON')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            // Verificar que el usuario tenga inversión en este vehículo
            $investment = Investment::with(['vehicle.negocio'])
                ->where('user_id', $user->id)
                ->where('vehicle_id', $vehicleId)
                ->first();

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversión en este vehículo'
                ], 404);
            }

            $vehicle = Vehicle::with(['negocio'])->findOrFail($vehicleId);

            // Calcular rendimiento del vehículo
            $ingresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'ingreso')
                ->where('estado', true)
                ->sum('importe_total');

            $egresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'egreso')
                ->where('estado', true)
                ->sum('importe_total');

            $millas = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->whereNotNull('millas')
                ->sum('millas');

            $numeroCargas = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id')
                ->where('estado', true)
                ->count();

            $utilidad = $ingresos - $egresos;
            $roi = $investment->monto_inversion > 0
                ? ($utilidad / $investment->monto_inversion) * 100
                : 0;

            $promedioPorCarga = $numeroCargas > 0 ? $ingresos / $numeroCargas : 0;
            $pagoPorMilla = $millas > 0 ? $ingresos / $millas : 0;

            // Últimas transacciones
            $ultimasTransacciones = FinancialTransactions::with(['categoria', 'metodo'])
                ->where('vehicle_id', $vehicleId)
                ->where('estado', true)
                ->orderBy('fecha', 'desc')
                ->limit(15)
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'tipo' => ucfirst($tx->tipo_de_transaccion),
                        'categoria' => $tx->categoria ? $tx->categoria->nombre : 'Sin categoría',
                        'metodo' => $tx->metodo ? $tx->metodo->nombre : 'Sin método',
                        'monto' => round($tx->importe_total, 2),
                        'fecha' => Carbon::parse($tx->fecha)->format('d/m/Y'),
                        'millas' => $tx->millas ?? 0,
                        'cliente' => $tx->cliente_proveedor ?? 'No especificado',
                        'destino' => $tx->destino ?? 'No especificado',
                        'descripcion' => $tx->item ?? $tx->observaciones ?? 'Sin descripción',
                    ];
                });

            // Transacciones por categoría
            $transaccionesPorCategoria = FinancialTransactions::with('categoria')
                ->where('vehicle_id', $vehicleId)
                ->where('estado', true)
                ->select(
                    'categoria_id',
                    'tipo_de_transaccion',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('categoria_id', 'tipo_de_transaccion')
                ->get()
                ->map(function ($item) {
                    return [
                        'categoria' => $item->categoria ? $item->categoria->nombre : 'Sin categoría',
                        'tipo' => ucfirst($item->tipo_de_transaccion),
                        'cantidad' => $item->cantidad,
                        'total' => round($item->total, 2),
                    ];
                });

            return response()->json([
                'success' => true,
                'vehiculo' => [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => $vehicle->tipo_propiedad,
                    'numero_motor' => $vehicle->numero_motor,
                    'numero_chasis' => $vehicle->numero_chasis,
                    'color' => $vehicle->color,
                ],
                'negocio' => $vehicle->negocio ? [
                    'id' => $vehicle->negocio->id,
                    'nombre' => $vehicle->negocio->nombre,
                    'descripcion' => $vehicle->negocio->descripcion,
                ] : null,
                'mi_inversion' => [
                    'monto' => round($investment->monto_inversion, 2),
                    'estado' => $investment->estado,
                    'fecha_inversion' => $investment->created_at
                        ? $investment->created_at->format('d/m/Y')
                        : 'No especificada',
                    'descripcion' => $investment->descripcion ?? 'Sin descripción',
                    'notas' => $investment->notas ?? 'Sin notas',
                ],
                'rendimiento' => [
                    'ingresos' => round($ingresos, 2),
                    'egresos' => round($egresos, 2),
                    'utilidad' => round($utilidad, 2),
                    'roi_porcentaje' => round($roi, 2),
                    'millas_recorridas' => round($millas, 2),
                    'numero_cargas' => $numeroCargas,
                    'promedio_por_carga' => round($promedioPorCarga, 2),
                    'pago_por_milla' => round($pagoPorMilla, 2),
                ],
                'transacciones_por_categoria' => $transaccionesPorCategoria,
                'ultimas_transacciones' => $ultimasTransacciones,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los detalles del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener rendimiento histórico de mis inversiones
     */
    public function performanceHistory(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('INVERSIONISTA LEASE ON')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            $fechaInicio = $request->fecha_inicio ?? Carbon::now()->subMonths(6)->format('Y-m-d');
            $fechaFin = $request->fecha_fin ?? Carbon::now()->format('Y-m-d');

            $investments = Investment::with(['vehicle'])
                ->where('user_id', $user->id)
                ->where('estado', 'activo')
                ->where('active', true)
                ->get();

            if ($investments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones activas'
                ], 404);
            }

            $vehiculosIds = $investments->pluck('vehicle_id')->filter()->unique();

            // Rendimiento mensual
            $rendimientoMensual = FinancialTransactions::whereIn('vehicle_id', $vehiculosIds)
                ->where('estado', true)
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->select(
                    DB::raw('DATE_FORMAT(fecha, "%Y-%m") as mes'),
                    'tipo_de_transaccion',
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('mes', 'tipo_de_transaccion')
                ->orderBy('mes')
                ->get();

            $rendimientoPorMes = [];
            foreach ($rendimientoMensual as $item) {
                $mes = $item->mes;
                if (!isset($rendimientoPorMes[$mes])) {
                    $rendimientoPorMes[$mes] = [
                        'mes' => Carbon::createFromFormat('Y-m', $mes)->format('M Y'),
                        'ingresos' => 0,
                        'egresos' => 0,
                        'utilidad' => 0,
                    ];
                }

                if ($item->tipo_de_transaccion === 'ingreso') {
                    $rendimientoPorMes[$mes]['ingresos'] = round($item->total, 2);
                } else {
                    $rendimientoPorMes[$mes]['egresos'] = round($item->total, 2);
                }

                $rendimientoPorMes[$mes]['utilidad'] =
                    $rendimientoPorMes[$mes]['ingresos'] - $rendimientoPorMes[$mes]['egresos'];
            }

            // ROI por vehículo
            $roiPorVehiculo = [];
            foreach ($investments as $investment) {
                if (!$investment->vehicle) continue;

                $ingresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->where('estado', true)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $egresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->where('tipo_de_transaccion', 'egreso')
                    ->where('estado', true)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $utilidad = $ingresos - $egresos;
                $roi = $investment->monto_inversion > 0
                    ? ($utilidad / $investment->monto_inversion) * 100
                    : 0;

                $roiPorVehiculo[] = [
                    'vehiculo' => [
                        'id' => $investment->vehicle->id,
                        'codigo_unico' => $investment->vehicle->codigo_unico,
                        'numero_placa' => $investment->vehicle->numero_placa,
                    ],
                    'inversion' => round($investment->monto_inversion, 2),
                    'rendimiento' => [
                        'ingresos' => round($ingresos, 2),
                        'egresos' => round($egresos, 2),
                        'utilidad' => round($utilidad, 2),
                        'roi_porcentaje' => round($roi, 2),
                    ],
                ];
            }

            return response()->json([
                'success' => true,
                'periodo' => [
                    'fecha_inicio' => Carbon::parse($fechaInicio)->format('d/m/Y'),
                    'fecha_fin' => Carbon::parse($fechaFin)->format('d/m/Y'),
                    'dias' => Carbon::parse($fechaInicio)->diffInDays(Carbon::parse($fechaFin)) + 1,
                ],
                'rendimiento_mensual' => array_values($rendimientoPorMes),
                'roi_por_vehiculo' => $roiPorVehiculo,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el historial de rendimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Comparar rendimiento entre mis vehículos
     */
    public function compareVehicles(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('INVERSIONISTA LEASE ON')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            $fechaInicio = $request->fecha_inicio ?? Carbon::now()->startOfMonth()->format('Y-m-d');
            $fechaFin = $request->fecha_fin ?? Carbon::now()->format('Y-m-d');

            $investments = Investment::with(['vehicle.negocio'])
                ->where('user_id', $user->id)
                ->where('estado', 'activo')
                ->where('active', true)
                ->whereNotNull('vehicle_id')
                ->get();

            if ($investments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones en vehículos'
                ], 404);
            }

            $comparativa = [];
            foreach ($investments as $investment) {
                if (!$investment->vehicle) continue;

                $ingresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->where('estado', true)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $egresos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->where('tipo_de_transaccion', 'egreso')
                    ->where('estado', true)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $millas = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->whereNotNull('millas')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('millas');

                $cargas = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                    ->where('tipo_de_transaccion', 'ingreso')
                    ->whereNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->count();

                $utilidad = $ingresos - $egresos;
                $roi = $investment->monto_inversion > 0
                    ? ($utilidad / $investment->monto_inversion) * 100
                    : 0;

                $comparativa[] = [
                    'vehiculo' => [
                        'id' => $investment->vehicle->id,
                        'codigo_unico' => $investment->vehicle->codigo_unico,
                        'numero_placa' => $investment->vehicle->numero_placa,
                        'marca' => $investment->vehicle->marca,
                        'modelo' => $investment->vehicle->modelo,
                    ],
                    'negocio' => $investment->vehicle->negocio ? [
                        'id' => $investment->vehicle->negocio->id,
                        'nombre' => $investment->vehicle->negocio->nombre,
                    ] : null,
                    'inversion' => round($investment->monto_inversion, 2),
                    'metricas' => [
                        'ingresos' => round($ingresos, 2),
                        'egresos' => round($egresos, 2),
                        'utilidad' => round($utilidad, 2),
                        'roi_porcentaje' => round($roi, 2),
                        'millas' => round($millas, 2),
                        'cargas' => $cargas,
                        'promedio_por_carga' => $cargas > 0 ? round($ingresos / $cargas, 2) : 0,
                        'pago_por_milla' => $millas > 0 ? round($ingresos / $millas, 2) : 0,
                    ],
                ];
            }

            // Ordenar por ROI descendente
            usort($comparativa, function ($a, $b) {
                return $b['metricas']['roi_porcentaje'] <=> $a['metricas']['roi_porcentaje'];
            });

            return response()->json([
                'success' => true,
                'periodo' => [
                    'fecha_inicio' => Carbon::parse($fechaInicio)->format('d/m/Y'),
                    'fecha_fin' => Carbon::parse($fechaFin)->format('d/m/Y'),
                    'dias' => Carbon::parse($fechaInicio)->diffInDays(Carbon::parse($fechaFin)) + 1,
                ],
                'comparativa' => $comparativa,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al comparar vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
