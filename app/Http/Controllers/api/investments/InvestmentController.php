<?php

namespace App\Http\Controllers\api\investments;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Investment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class InvestmentController extends Controller
{
    /**
     * Mostrar listado de inversiones
     */
    public function index()
    {
        $investments = Investment::with([
            'user.generalData',
            'vehicle',
            'business'
        ])
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Formatear datos para incluir información del inversionista
        $investments->getCollection()->transform(function ($investment) {
            return [
                'id' => $investment->id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                    'ciudad' => $investment->user?->generalData?->ciudad,
                    'departamento' => $investment->user?->generalData?->departamento,
                ],
                'vehicle' => $investment->vehicle ? [
                    'id' => $investment->vehicle->id,
                    'placa' => $investment->vehicle->placa,
                    'marca' => $investment->vehicle->marca,
                    'modelo' => $investment->vehicle->modelo,
                ] : null,
                'business' => $investment->business ? [
                    'id' => $investment->business->id,
                    'nombre' => $investment->business->nombre,
                ] : null,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
                'updated_at' => $investment->updated_at,
            ];
        });

        return response()->json($investments);
    }

    /**
     * Mostrar formulario de creación
     */
    public function create()
    {
        $users = User::with('generalData')
            ->role('inversionista') // Filtrar solo usuarios con rol inversionista
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'email' => $user->email,
                    'nombre_completo' => $user->generalData
                        ? $user->generalData->nombre . ' ' . $user->generalData->apellido
                        : 'Sin datos',
                    'documento_identidad' => $user->generalData?->documento_identidad,
                    'celular' => $user->generalData?->celular,
                ];
            });

        $vehicles = Vehicle::select('id', 'marca', 'modelo', 'codigo_unico')->get();
        $businesses = Business::select('id', 'nombre')->get();

        return response()->json([
            'users' => $users,
            'vehicles' => $vehicles,
            'businesses' => $businesses,
        ]);
    }


    /**
     * Crear nueva inversión
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'user_id' => 'nullable|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'business_id' => 'nullable|exists:businesses,id',
            'monto_inversion' => 'nullable|numeric|min:0|max:99999999.99',
            'descripcion' => 'nullable|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'active' => 'boolean',
            'estado' => 'in:pendiente,activo,completado,cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
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
                'estado' => $request->estado ?? 'pendiente',
            ]);

            $investment->load(['user.generalData', 'vehicle', 'business']);

            // Formatear respuesta
            $formattedInvestment = [
                'id' => $investment->id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'vehicle' => $investment->vehicle,
                'business' => $investment->business,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
                'updated_at' => $investment->updated_at,
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión creada exitosamente',
                'data' => $formattedInvestment
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

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
        $investment = Investment::with(['user.generalData', 'vehicle', 'business'])->find($id);

        if (!$investment) {
            return response()->json([
                'success' => false,
                'message' => 'Inversión no encontrada'
            ], 404);
        }

        // Formatear respuesta
        $formattedInvestment = [
            'id' => $investment->id,
            'inversionista' => [
                'id' => $investment->user?->id,
                'email' => $investment->user?->email,
                'nombre_completo' => $investment->user?->generalData
                    ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                    : null,
                'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                'celular' => $investment->user?->generalData?->celular,
                'direccion' => $investment->user?->generalData?->direccion,
                'ciudad' => $investment->user?->generalData?->ciudad,
                'departamento' => $investment->user?->generalData?->departamento,
                'contacto_emergencia' => [
                    'nombre' => $investment->user?->generalData?->contacto_emergencia_nombre,
                    'telefono' => $investment->user?->generalData?->contacto_emergencia_telefono,
                ],
            ],
            'vehicle' => $investment->vehicle,
            'business' => $investment->business,
            'monto_inversion' => $investment->monto_inversion,
            'descripcion' => $investment->descripcion,
            'notas' => $investment->notas,
            'active' => $investment->active,
            'estado' => $investment->estado,
            'created_at' => $investment->created_at,
            'updated_at' => $investment->updated_at,
        ];

        return response()->json([
            'success' => true,
            'data' => $formattedInvestment
        ]);
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
            'user_id' => 'nullable|exists:users,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'business_id' => 'nullable|exists:businesses,id',
            'monto_inversion' => 'nullable|numeric|min:0|max:99999999.99',
            'descripcion' => 'nullable|string|max:1000',
            'notas' => 'nullable|string|max:2000',
            'active' => 'boolean',
            'estado' => 'in:pendiente,activo,completado,cancelado',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            DB::beginTransaction();

            $investment->update($request->all());
            $investment->load(['user.generalData', 'vehicle', 'business']);

            // Formatear respuesta
            $formattedInvestment = [
                'id' => $investment->id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'vehicle' => $investment->vehicle,
                'business' => $investment->business,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
                'updated_at' => $investment->updated_at,
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión actualizada exitosamente',
                'data' => $formattedInvestment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

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
        $investment = Investment::find($id);

        if (!$investment) {
            return response()->json([
                'success' => false,
                'message' => 'Inversión no encontrada'
            ], 404);
        }

        try {
            $investment->delete();

            return response()->json([
                'success' => true,
                'message' => 'Inversión eliminada exitosamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la inversión',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener inversiones de un usuario específico
     */
    public function byUser($userId)
    {
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

        // Formatear inversiones
        $formattedInvestments = $investments->map(function ($investment) {
            return [
                'id' => $investment->id,
                'vehicle' => $investment->vehicle,
                'business' => $investment->business,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'inversionista' => [
                'id' => $user->id,
                'email' => $user->email,
                'nombre_completo' => $user->generalData
                    ? $user->generalData->nombre . ' ' . $user->generalData->apellido
                    : null,
                'documento_identidad' => $user->generalData?->documento_identidad,
                'celular' => $user->generalData?->celular,
                'ciudad' => $user->generalData?->ciudad,
            ],
            'inversiones' => $formattedInvestments,
            'total_invertido' => $total,
            'cantidad_inversiones' => $investments->count(),
            'inversiones_activas' => $investments->where('active', true)->count(),
        ]);
    }

    /**
     * Obtener inversiones de un negocio específico
     */
    public function byBusiness($businessId)
    {
        $business = Business::find($businessId);

        if (!$business) {
            return response()->json([
                'success' => false,
                'message' => 'Negocio no encontrado'
            ], 404);
        }

        $investments = Investment::with(['user.generalData', 'vehicle'])
            ->where('business_id', $businessId)
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $investments->sum('monto_inversion');

        // Formatear inversiones con datos de inversionistas
        $formattedInvestments = $investments->map(function ($investment) {
            return [
                'id' => $investment->id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'vehicle' => $investment->vehicle,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'business' => $business,
            'inversiones' => $formattedInvestments,
            'total_invertido' => $total,
            'cantidad_inversionistas' => $investments->unique('user_id')->count(),
        ]);
    }

    /**
     * Obtener inversiones de un vehículo específico
     */
    public function byVehicle($vehicleId)
    {
        $vehicle = Vehicle::find($vehicleId);

        if (!$vehicle) {
            return response()->json([
                'success' => false,
                'message' => 'Vehículo no encontrado'
            ], 404);
        }

        $investments = Investment::with(['user.generalData', 'business'])
            ->where('vehicle_id', $vehicleId)
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $investments->sum('monto_inversion');

        // Formatear inversiones con datos de inversionistas
        $formattedInvestments = $investments->map(function ($investment) {
            return [
                'id' => $investment->id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'business' => $investment->business,
                'monto_inversion' => $investment->monto_inversion,
                'descripcion' => $investment->descripcion,
                'active' => $investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'vehicle' => $vehicle,
            'inversiones' => $formattedInvestments,
            'total_invertido' => $total,
            'cantidad_inversionistas' => $investments->unique('user_id')->count(),
        ]);
    }

    /**
     * Cambiar estado de la inversión
     */
    public function changeStatus(Request $request, $id)
    {
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

        $investment->load(['user.generalData', 'vehicle', 'business']);

        return response()->json([
            'success' => true,
            'message' => 'Estado actualizado exitosamente',
            'data' => $investment
        ]);
    }

    /**
     * Activar/Desactivar inversión
     */
    public function toggleActive($id)
    {
        $investment = Investment::find($id);

        if (!$investment) {
            return response()->json([
                'success' => false,
                'message' => 'Inversión no encontrada'
            ], 404);
        }

        $investment->active = !$investment->active;
        $investment->save();

        $investment->load(['user.generalData', 'vehicle', 'business']);

        return response()->json([
            'success' => true,
            'message' => $investment->active ? 'Inversión activada' : 'Inversión desactivada',
            'data' => $investment
        ]);
    }

    /**
     * Obtener estadísticas generales de inversiones
     */
    public function statistics()
    {
        $totalInversiones = Investment::count();
        $totalMonto = Investment::sum('monto_inversion');
        $inversionesActivas = Investment::where('active', true)->count();
        $inversionesPorEstado = Investment::select('estado', DB::raw('count(*) as total'))
            ->groupBy('estado')
            ->get();

        $topInversionistas = Investment::select('user_id', DB::raw('SUM(monto_inversion) as total_invertido'))
            ->with('user.generalData')
            ->groupBy('user_id')
            ->orderBy('total_invertido', 'desc')
            ->limit(10)
            ->get()
            ->map(function ($investment) {
                return [
                    'id' => $investment->user?->id,
                    'nombre_completo' => $investment->user?->generalData
                        ? $investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido
                        : null,
                    'email' => $investment->user?->email,
                    'total_invertido' => $investment->total_invertido,
                ];
            });

        return response()->json([
            'success' => true,
            'estadisticas' => [
                'total_inversiones' => $totalInversiones,
                'total_monto_invertido' => $totalMonto,
                'inversiones_activas' => $inversionesActivas,
                'inversiones_por_estado' => $inversionesPorEstado,
                'top_inversionistas' => $topInversionistas,
            ]
        ]);
    }
}
