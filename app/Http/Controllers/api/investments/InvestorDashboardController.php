<?php

namespace App\Http\Controllers\api\investments;

use App\Http\Controllers\Controller;
use App\Models\Investment;
use App\Models\FinancialTransactions;
use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InvestorDashboardController extends Controller
{
    /**
     * Dashboard del inversionista - Solo sus inversiones y negocios
     */
    public function index()
    {
        try {
            $user = Auth::user();

            // Verificar que el usuario tenga rol de inversionista
            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a este dashboard'
                ], 403);
            }

            // Obtener SOLO las inversiones del inversionista autenticado
            $investments = Investment::with(['vehicle', 'business'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
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
                        'rendimiento_total' => 0,
                        'gastos_total' => 0,
                        'utilidad_neta' => 0,
                        'roi_porcentaje' => 0,
                    ],
                    'inversiones_por_estado' => [
                        'pendiente' => 0,
                        'activo' => 0,
                        'completado' => 0,
                        'cancelado' => 0,
                    ],
                    'actividad_reciente' => [],
                ]);
            }

            // Calcular estadísticas básicas
            $totalInversiones = $investments->count();
            $capitalTotal = $investments->sum('monto_inversion');
            $inversionesActivas = $investments->where('active', true)->count();

            // Obtener SOLO los negocios y vehículos donde el usuario ha invertido
            $negociosIds = $investments->whereNotNull('business_id')->pluck('business_id')->unique();
            $vehiculosIds = $investments->whereNotNull('vehicle_id')->pluck('vehicle_id')->unique();

            $negociosUnicos = $negociosIds->count();
            $vehiculosUnicos = $vehiculosIds->count();

            // Inversiones por estado
            $inversionesPorEstado = [
                'pendiente' => $investments->where('estado', 'pendiente')->count(),
                'activo' => $investments->where('estado', 'activo')->count(),
                'completado' => $investments->where('estado', 'completado')->count(),
                'cancelado' => $investments->where('estado', 'cancelado')->count(),
            ];

            // Calcular rendimiento SOLO de SUS negocios y vehículos
            $rendimientoNegocios = 0;
            $gastosNegocios = 0;

            if ($negociosIds->isNotEmpty()) {
                // Ingresos SIN caja operativa (regla de negocio)
                $rendimientoNegocios = FinancialTransactions::whereIn('negocio_id', $negociosIds)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id')
                    ->where('estado', true)
                    ->sum('importe_total');

                // Egresos TODOS (incluye caja operativa - gastos reales)
                $gastosNegocios = FinancialTransactions::whereIn('negocio_id', $negociosIds)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->where('estado', true)
                    ->sum('importe_total');
            }

            $rendimientoVehiculos = 0;
            $gastosVehiculos = 0;

            if ($vehiculosIds->isNotEmpty()) {
                $rendimientoVehiculos = FinancialTransactions::whereIn('vehicle_id', $vehiculosIds)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                $gastosVehiculos = FinancialTransactions::whereIn('vehicle_id', $vehiculosIds)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->where('estado', true)
                    ->sum('importe_total');
            }

            $rendimientoTotal = $rendimientoNegocios + $rendimientoVehiculos;
            $gastosTotal = $gastosNegocios + $gastosVehiculos;
            $utilidadNeta = $rendimientoTotal - $gastosTotal;

            // ROI calculado
            $roi = $capitalTotal > 0 ? ($utilidadNeta / $capitalTotal) * 100 : 0;

            // Actividad reciente (últimas 5 transacciones de sus inversiones)
            $actividadReciente = [];

            if ($negociosIds->isNotEmpty() || $vehiculosIds->isNotEmpty()) {
                $transaccionesRecientes = FinancialTransactions::with(['negocio', 'vehicle', 'categoria'])
                    ->where(function ($query) use ($negociosIds, $vehiculosIds) {
                        if ($negociosIds->isNotEmpty()) {
                            $query->whereIn('negocio_id', $negociosIds);
                        }
                        if ($vehiculosIds->isNotEmpty()) {
                            $query->orWhereIn('vehicle_id', $vehiculosIds);
                        }
                    })
                    ->where('estado', true)
                    ->orderBy('fecha', 'desc')
                    ->limit(5)
                    ->get();

                $actividadReciente = $transaccionesRecientes->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'tipo' => ucfirst($tx->tipo_de_transaccion),
                        'nombre' => $tx->negocio?->nombre ?? $tx->vehicle?->placa ?? 'N/A',
                        'categoria' => $tx->categoria?->nombre ?? 'Sin categoría',
                        'monto' => $tx->importe_total,
                        'fecha' => Carbon::parse($tx->fecha)->diffForHumans(),
                        'descripcion' => $tx->item ?? $tx->observaciones,
                    ];
                });
            }

            return response()->json([
                'success' => true,
                'inversionista' => [
                    'id' => $user->id,
                    'nombre_completo' => $user->generalData
                        ? $user->generalData->nombre . ' ' . $user->generalData->apellido
                        : 'Sin datos',
                    'email' => $user->email,
                    'celular' => $user->generalData?->celular,
                    'ciudad' => $user->generalData?->ciudad,
                ],
                'estadisticas' => [
                    'total_inversiones' => $totalInversiones,
                    'capital_total' => round($capitalTotal, 2),
                    'inversiones_activas' => $inversionesActivas,
                    'negocios_unicos' => $negociosUnicos,
                    'vehiculos_unicos' => $vehiculosUnicos,
                    'rendimiento_total' => round($rendimientoTotal, 2),
                    'gastos_total' => round($gastosTotal, 2),
                    'utilidad_neta' => round($utilidadNeta, 2),
                    'roi_porcentaje' => round($roi, 2),
                ],
                'inversiones_por_estado' => $inversionesPorEstado,
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
     * Obtener SOLO las inversiones del inversionista autenticado
     */
    public function myInvestments()
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            // SOLO inversiones del usuario autenticado
            $investments = Investment::with(['vehicle', 'business'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($investment) {
                    // Calcular rendimiento individual POR INVERSIÓN
                    $rendimiento = 0;
                    $gastos = 0;

                    if ($investment->business_id) {
                        // Ingresos SIN caja operativa
                        $rendimiento = FinancialTransactions::where('negocio_id', $investment->business_id)
                            ->where('tipo_de_transaccion', 'Ingreso')
                            ->whereNull('caja_operativa_id')
                            ->where('estado', true)
                            ->sum('importe_total');

                        // Egresos TODOS
                        $gastos = FinancialTransactions::where('negocio_id', $investment->business_id)
                            ->where('tipo_de_transaccion', 'Egreso')
                            ->where('estado', true)
                            ->sum('importe_total');
                    }

                    if ($investment->vehicle_id) {
                        $rendimiento = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                            ->where('tipo_de_transaccion', 'Ingreso')
                            ->where('estado', true)
                            ->sum('importe_total');

                        $gastos = FinancialTransactions::where('vehicle_id', $investment->vehicle_id)
                            ->where('tipo_de_transaccion', 'Egreso')
                            ->where('estado', true)
                            ->sum('importe_total');
                    }

                    $utilidad = $rendimiento - $gastos;
                    $roi = $investment->monto_inversion > 0
                        ? ($utilidad / $investment->monto_inversion) * 100
                        : 0;

                    return [
                        'id' => $investment->id,
                        'business' => $investment->business ? [
                            'id' => $investment->business->id,
                            'nombre' => $investment->business->nombre,
                            'descripcion' => $investment->business->descripcion,
                        ] : null,
                        'vehicle' => $investment->vehicle ? [
                            'id' => $investment->vehicle->id,
                            'placa' => $investment->vehicle->placa,
                            'marca' => $investment->vehicle->marca,
                            'modelo' => $investment->vehicle->modelo,
                            'codigo_unico' => $investment->vehicle->codigo_unico,
                        ] : null,
                        'monto_inversion' => round($investment->monto_inversion, 2),
                        'descripcion' => $investment->descripcion,
                        'notas' => $investment->notas,
                        'estado' => $investment->estado,
                        'active' => $investment->active,
                        'rendimiento' => [
                            'ingresos' => round($rendimiento, 2),
                            'egresos' => round($gastos, 2),
                            'utilidad_neta' => round($utilidad, 2),
                            'roi_porcentaje' => round($roi, 2),
                        ],
                        'created_at' => $investment->created_at ? $investment->created_at->format('Y-m-d H:i:s') : null,
                        'updated_at' => $investment->updated_at ? $investment->updated_at->format('Y-m-d H:i:s') : null,
                    ];
                });

            $total = $investments->sum('monto_inversion');
            $utilidadTotal = $investments->sum('rendimiento.utilidad_neta');

            return response()->json([
                'success' => true,
                'data' => $investments,
                'resumen' => [
                    'total_invertido' => round($total, 2),
                    'utilidad_total' => round($utilidadTotal, 2),
                    'cantidad_inversiones' => $investments->count(),
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
     * Obtener estado financiero de un negocio específico donde el usuario invirtió
     */
    public function businessFinancialStatement(Request $request, $businessId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            // Verificar que el usuario tenga una inversión en este negocio
            $investment = Investment::where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->first();

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones en este negocio'
                ], 404);
            }

            $fechaInicial = $request->fecha_inicio ?? now()->startOfMonth()->format('Y-m-d');
            $fechaFinal = $request->fecha_fin ?? now()->endOfMonth()->format('Y-m-d');

            $business = Business::findOrFail($businessId);

            // INGRESOS SIN CAJA OPERATIVA (regla de negocio)
            $totalIngresos = FinancialTransactions::where('negocio_id', $businessId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->where('estado', true)
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            // EGRESOS TODOS (incluye caja operativa)
            $totalEgresos = FinancialTransactions::where('negocio_id', $businessId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->where('estado', true)
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            $margenBruto = $totalIngresos - $totalEgresos;
            $rentabilidad = $totalIngresos > 0 ? ($margenBruto / $totalIngresos) * 100 : 0;

            // Transacciones por estado
            $transaccionesPorEstado = FinancialTransactions::where('negocio_id', $businessId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($q) {
                            $q->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->where('estado', true)
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(financial_transactions.importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get();

            $detalleEstados = [];
            foreach ($transaccionesPorEstado as $tx) {
                $estadoId = $tx->estado_id;
                if (!isset($detalleEstados[$estadoId])) {
                    $detalleEstados[$estadoId] = [
                        'estado_id' => $estadoId,
                        'estado_nombre' => strtoupper($tx->estado_nombre),
                        'ingresos' => 0,
                        'egresos' => 0,
                        'balance' => 0,
                    ];
                }

                if ($tx->tipo_de_transaccion === 'Ingreso') {
                    $detalleEstados[$estadoId]['ingresos'] = floatval($tx->total_importe);
                } else {
                    $detalleEstados[$estadoId]['egresos'] = floatval($tx->total_importe);
                }

                $detalleEstados[$estadoId]['balance'] =
                    $detalleEstados[$estadoId]['ingresos'] - $detalleEstados[$estadoId]['egresos'];
            }

            return response()->json([
                'success' => true,
                'negocio' => [
                    'id' => $business->id,
                    'nombre' => $business->nombre,
                    'descripcion' => $business->descripcion,
                ],
                'periodo' => [
                    'fecha_inicial' => $fechaInicial,
                    'fecha_final' => $fechaFinal,
                    'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1,
                ],
                'tu_inversion' => [
                    'monto' => round($investment->monto_inversion, 2),
                    'estado' => $investment->estado,
                    'fecha_inversion' => $investment->created_at ? $investment->created_at->format('Y-m-d') : 'No especificada',
                ],
                'resumen_financiero' => [
                    'total_ingresos' => round($totalIngresos, 2),
                    'total_egresos' => round($totalEgresos, 2),
                    'margen_bruto' => round($margenBruto, 2),
                    'rentabilidad_porcentaje' => round($rentabilidad, 2),
                ],
                'detalle_por_estado' => array_values($detalleEstados),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar el estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener transacciones de un negocio específico donde el usuario invirtió
     */
    public function businessTransactions(Request $request, $businessId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            // Verificar que el usuario tenga una inversión en este negocio
            $investment = Investment::where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->first();

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones en este negocio'
                ], 404);
            }

            $query = FinancialTransactions::with(['metodo', 'categoria', 'estadoDeTransaccion'])
                ->where('negocio_id', $businessId)
                ->where('estado', true);

            // Aplicar regla de negocio: Solo ingresos sin caja operativa
            $query->where(function ($q) {
                $q->where('tipo_de_transaccion', 'Egreso')
                    ->orWhere(function ($subQ) {
                        $subQ->where('tipo_de_transaccion', 'Ingreso')
                            ->whereNull('caja_operativa_id');
                    });
            });

            // Filtros opcionales
            if ($request->has('tipo')) {
                $query->where('tipo_de_transaccion', $request->tipo);
            }

            if ($request->has('fecha_inicio') && $request->has('fecha_fin')) {
                $query->whereBetween('fecha', [$request->fecha_inicio, $request->fecha_fin]);
            }

            $transacciones = $query->orderBy('fecha', 'desc')
                ->paginate($request->per_page ?? 15);

            return response()->json([
                'success' => true,
                'data' => $transacciones,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar las transacciones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener lista de negocios donde el inversionista tiene inversiones
     */
    public function myBusinesses()
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            $investments = Investment::with('business')
                ->where('user_id', $user->id)
                ->whereNotNull('business_id')
                ->get();

            $negocios = $investments->map(function ($investment) {
                if (!$investment->business) return null;

                // Calcular rendimiento del negocio
                $ingresos = FinancialTransactions::where('negocio_id', $investment->business_id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id')
                    ->where('estado', true)
                    ->sum('importe_total');

                $egresos = FinancialTransactions::where('negocio_id', $investment->business_id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->where('estado', true)
                    ->sum('importe_total');

                return [
                    'inversion_id' => $investment->id,
                    'negocio' => [
                        'id' => $investment->business->id,
                        'nombre' => $investment->business->nombre,
                        'descripcion' => $investment->business->descripcion,
                    ],
                    'mi_inversion' => round($investment->monto_inversion, 2),
                    'estado_inversion' => $investment->estado,
                    'rendimiento' => [
                        'ingresos' => round($ingresos, 2),
                        'egresos' => round($egresos, 2),
                        'utilidad' => round($ingresos - $egresos, 2),
                    ],
                ];
            })->filter()->values();

            return response()->json([
                'success' => true,
                'data' => $negocios,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los negocios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener detalles completos de un negocio donde el inversionista ha invertido
     */
    public function businessDetails($businessId)
    {
        try {
            $user = Auth::user();

            if (!$user->hasRole('inversionista')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para acceder a esta información'
                ], 403);
            }

            // Verificar que el usuario tenga una inversión en este negocio
            $investment = Investment::with('business')
                ->where('user_id', $user->id)
                ->where('business_id', $businessId)
                ->first();

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones en este negocio'
                ], 404);
            }

            $business = $investment->business;

            // Calcular rendimiento del negocio
            $ingresos = FinancialTransactions::where('negocio_id', $businessId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->where('estado', true)
                ->sum('importe_total');

            $egresos = FinancialTransactions::where('negocio_id', $businessId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->where('estado', true)
                ->sum('importe_total');

            $utilidad = $ingresos - $egresos;
            $roi = $investment->monto_inversion > 0
                ? ($utilidad / $investment->monto_inversion) * 100
                : 0;

            // Obtener últimas transacciones
            $ultimasTransacciones = FinancialTransactions::with(['categoria', 'metodo'])
                ->where('negocio_id', $businessId)
                ->where('estado', true)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($q) {
                            $q->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->orderBy('fecha', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($tx) {
                    return [
                        'id' => $tx->id,
                        'tipo' => ucfirst($tx->tipo_de_transaccion),
                        'categoria' => $tx->categoria?->nombre ?? 'Sin categoría',
                        'metodo' => $tx->metodo?->nombre ?? 'Sin método',
                        'monto' => $tx->importe_total,
                        'fecha' => $tx->fecha ? Carbon::parse($tx->fecha)->format('d/m/Y') : 'No especificada',
                        'descripcion' => $tx->item ?? $tx->observaciones ?? 'Sin descripción',
                    ];
                });

            return response()->json([
                'success' => true,
                'negocio' => [
                    'id' => $business->id,
                    'nombre' => $business->nombre,
                    'descripcion' => $business->descripcion,
                    'direccion' => $business->direccion ?? 'No especificada',
                    'telefono' => $business->telefono ?? 'No especificado',
                    'email' => $business->email ?? 'No especificado',
                    'created_at' => $business->created_at ? $business->created_at->format('d/m/Y') : 'No especificada',
                ],
                'mi_inversion' => [
                    'id' => $investment->id,
                    'monto' => round($investment->monto_inversion, 2),
                    'estado' => $investment->estado,
                    'fecha_inversion' => $investment->created_at ? $investment->created_at->format('d/m/Y') : 'No especificada',
                    'descripcion' => $investment->descripcion ?? 'Sin descripción',
                ],
                'rendimiento' => [
                    'ingresos' => round($ingresos, 2),
                    'egresos' => round($egresos, 2),
                    'utilidad' => round($utilidad, 2),
                    'roi_porcentaje' => round($roi, 2),
                ],
                'ultimas_transacciones' => $ultimasTransacciones,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al cargar los detalles del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
