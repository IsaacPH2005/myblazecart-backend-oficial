<?php

namespace App\Http\Controllers\api\investments;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Investment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InvestmentController extends Controller
{
    /**
     * 🎯 ENDPOINT PARA EL PANEL DEL INVERSIONISTA (Usuario logueado)
     * GET /api/my-investments
     */
    public function myInvestments(Request $request)
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            Log::info('🔍 Panel Inversionista - Usuario:', [
                'user_id' => $user->id,
                'email' => $user->email
            ]);

            // Obtener inversiones del usuario autenticado
            $investments = Investment::with(['vehicle', 'business'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            Log::info('📊 Inversiones encontradas:', [
                'count' => $investments->count(),
                'user_id' => $user->id
            ]);

            // Calcular totales
            $totalInvertido = $investments->sum('monto_inversion');

            // Formatear inversiones
            $formattedInvestments = $investments->map(function ($investment) {
                return [
                    'id' => $investment->id,
                    'vehicle' => $investment->vehicle ? [
                        'id' => $investment->vehicle->id,
                        'numero_placa' => $investment->vehicle->numero_placa ?? 'N/A',
                        'marca' => $investment->vehicle->marca ?? 'N/A',
                        'modelo' => $investment->vehicle->modelo ?? 'N/A',
                        'año' => $investment->vehicle->año ?? null,
                        'codigo_unico' => $investment->vehicle->codigo_unico ?? 'N/A',
                        'nombre_completo' => trim(($investment->vehicle->marca ?? '') . ' ' . ($investment->vehicle->modelo ?? '') . ' - ' . ($investment->vehicle->numero_placa ?? '')),
                    ] : [
                        'id' => null,
                        'numero_placa' => 'Sin vehículo',
                        'marca' => 'N/A',
                        'modelo' => 'N/A',
                        'nombre_completo' => 'Sin vehículo asignado'
                    ],
                    'business' => $investment->business ? [
                        'id' => $investment->business->id,
                        'nombre' => $investment->business->nombre,
                    ] : [
                        'id' => null,
                        'nombre' => 'Sin negocio'
                    ],
                    'monto_inversion' => floatval($investment->monto_inversion),
                    'descripcion' => $investment->descripcion ?? '',
                    'notas' => $investment->notas ?? '',
                    'active' => (bool)$investment->active,
                    'estado' => $investment->estado,
                    'fecha_inversion' => $investment->created_at?->format('Y-m-d') ?? null,
                    'created_at' => $investment->created_at?->format('Y-m-d H:i:s') ?? null,
                    'updated_at' => $investment->updated_at?->format('Y-m-d H:i:s') ?? null,
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedInvestments,
                'resumen' => [
                    'total_invertido' => floatval($totalInvertido),
                    'utilidad_total' => 0, // Aquí puedes calcular utilidades si tienes ese campo
                    'cantidad_inversiones' => $investments->count(),
                    'inversiones_activas' => $investments->where('active', true)->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('❌ Error en myInvestments:', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * 🎯 LISTADO DE TODAS LAS INVERSIONES (Admin)
     * GET /api/investments
     */
    public function index()
    {
        try {
            $investments = Investment::with(['user.generalData', 'vehicle', 'business'])
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => $investments->items(),
                'pagination' => [
                    'current_page' => $investments->currentPage(),
                    'last_page' => $investments->lastPage(),
                    'per_page' => $investments->perPage(),
                    'total' => $investments->total(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Obtener datos para formulario de creación
     */
    public function create()
    {
        try {
            // Obtener usuarios con rol inversionista
            $users = User::with('generalData')
                ->whereHas('roles', function ($q) {
                    $q->where('name', 'inversionista');
                })
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'email' => $user->email,
                        'nombre_completo' => $user->generalData
                            ? trim($user->generalData->nombre . ' ' . $user->generalData->apellido)
                            : 'Sin nombre',
                        'documento_identidad' => $user->generalData?->documento_identidad,
                        'celular' => $user->generalData?->celular,
                    ];
                });

            $vehicles = Vehicle::where('estado', 'ACTIVO')
                ->select('id', 'marca', 'modelo', 'codigo_unico', 'numero_placa', 'año')
                ->orderBy('marca')
                ->get()
                ->map(function ($vehicle) {
                    return [
                        'id' => $vehicle->id,
                        'label' => "{$vehicle->codigo_unico} - {$vehicle->marca} {$vehicle->modelo} ({$vehicle->numero_placa})",
                        'marca' => $vehicle->marca,
                        'modelo' => $vehicle->modelo,
                        'numero_placa' => $vehicle->numero_placa,
                        'codigo_unico' => $vehicle->codigo_unico,
                    ];
                });

            $businesses = Business::where('estado', true)
                ->select('id', 'nombre')
                ->orderBy('nombre')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'users' => $users,
                    'vehicles' => $vehicles,
                    'businesses' => $businesses,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en create: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener datos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear nueva inversión
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'required|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'business_id' => 'nullable|exists:businesses,id',
            'monto_inversion' => 'required|numeric|min:0|max:99999999.99',
            'descripcion' => 'nullable|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'active' => 'boolean',
            'estado' => 'in:pendiente,activo,completado,cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $investment = Investment::create([
                'user_id' => $request->user_id,
                'vehicle_id' => $request->vehicle_id,
                'business_id' => $request->business_id,
                'monto_inversion' => $request->monto_inversion,
                'descripcion' => $request->descripcion,
                'notas' => $request->notas,
                'active' => $request->active ?? true,
                'estado' => $request->estado ?? 'activo',
            ]);

            Log::info('✅ Inversión creada:', ['id' => $investment->id]);

            $investment->load(['user.generalData', 'vehicle', 'business']);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión creada exitosamente',
                'data' => $investment
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en store: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la inversión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar una inversión específica
     */
    public function show($id)
    {
        try {
            $investment = Investment::with(['user.generalData', 'vehicle', 'business'])->find($id);

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inversión no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la inversión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar inversión
     */
    public function update(Request $request, $id)
    {
        $investment = Investment::find($id);

        if (!$investment) {
            return response()->json([
                'success' => false,
                'message' => 'Inversión no encontrada'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'sometimes|required|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'business_id' => 'nullable|exists:businesses,id',
            'monto_inversion' => 'sometimes|required|numeric|min:0|max:99999999.99',
            'descripcion' => 'nullable|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'active' => 'boolean',
            'estado' => 'in:pendiente,activo,completado,cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Errores de validación',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();
            $investment->update($request->all());
            $investment->load(['user.generalData', 'vehicle', 'business']);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión actualizada exitosamente',
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en update: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la inversión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar inversión
     */
    public function destroy($id)
    {
        try {
            $investment = Investment::find($id);

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inversión no encontrada'
                ], 404);
            }

            $investment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Inversión eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            Log::error('Error en destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la inversión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Inversiones por usuario
     */
    public function byUser($userId)
    {
        try {
            $user = User::with('generalData')->find($userId);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ], 404);
            }

            $investments = Investment::with(['vehicle', 'business'])
                ->where('user_id', $userId)
                ->orderBy('created_at', 'desc')
                ->get();

            $total = $investments->sum('monto_inversion');

            return response()->json([
                'success' => true,
                'inversionista' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'nombre_completo' => $user->generalData
                        ? trim($user->generalData->nombre . ' ' . $user->generalData->apellido)
                        : 'Sin nombre',
                ],
                'inversiones' => $investments,
                'total_invertido' => floatval($total),
                'cantidad_inversiones' => $investments->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error en byUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones del usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado
     */
    public function changeStatus(Request $request, $id)
    {
        try {
            $investment = Investment::find($id);

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inversión no encontrada'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'estado' => 'required|in:pendiente,activo,completado,cancelado',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $investment->estado = $request->estado;
            $investment->save();

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente',
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en changeStatus: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle active
     */
    public function toggleActive($id)
    {
        try {
            $investment = Investment::find($id);

            if (!$investment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Inversión no encontrada'
                ], 404);
            }

            $investment->active = !$investment->active;
            $investment->save();

            return response()->json([
                'success' => true,
                'message' => $investment->active ? 'Inversión activada' : 'Inversión desactivada',
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en toggleActive: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Estadísticas
     */
    public function statistics()
    {
        try {
            $totalInversiones = Investment::count();
            $totalMonto = Investment::sum('monto_inversion');
            $inversionesActivas = Investment::where('active', true)->count();

            return response()->json([
                'success' => true,
                'estadisticas' => [
                    'total_inversiones' => $totalInversiones,
                    'total_monto_invertido' => floatval($totalMonto),
                    'inversiones_activas' => $inversionesActivas,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
