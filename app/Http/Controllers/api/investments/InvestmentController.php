<?php

namespace App\Http\Controllers\api\investments;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Investment;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InvestmentController extends Controller
{
    /**
     * Mostrar listado de inversiones
     */
    public function index()
    {
        try {
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
                    'user_id' => $investment->user_id,
                    'vehicle_id' => $investment->vehicle_id,
                    'business_id' => $investment->business_id,
                    'inversionista' => [
                        'id' => $investment->user?->id,
                        'email' => $investment->user?->email,
                        'nombre_completo' => $investment->user?->generalData
                            ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                            : 'Sin nombre',
                        'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                        'celular' => $investment->user?->generalData?->celular,
                        'ciudad' => $investment->user?->generalData?->ciudad,
                        'departamento' => $investment->user?->generalData?->departamento,
                    ],
                    'vehicle' => $investment->vehicle ? [
                        'id' => $investment->vehicle->id,
                        'numero_placa' => $investment->vehicle->numero_placa,
                        'marca' => $investment->vehicle->marca,
                        'modelo' => $investment->vehicle->modelo,
                        'año' => $investment->vehicle->año,
                        'codigo_unico' => $investment->vehicle->codigo_unico,
                    ] : null,
                    'business' => $investment->business ? [
                        'id' => $investment->business->id,
                        'nombre' => $investment->business->nombre,
                    ] : null,
                    'monto_inversion' => floatval($investment->monto_inversion),
                    'descripcion' => $investment->descripcion,
                    'notas' => $investment->notas,
                    'active' => (bool)$investment->active,
                    'estado' => $investment->estado,
                    'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                    'updated_at' => $investment->updated_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Inversiones obtenidas exitosamente',
                'data' => $investments->items(),
                'pagination' => [
                    'current_page' => $investments->currentPage(),
                    'last_page' => $investments->lastPage(),
                    'per_page' => $investments->perPage(),
                    'total' => $investments->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar formulario de creación - Obtener datos necesarios
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
            Log::error('Error en InvestmentController@create: ' . $e->getMessage());
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
                'estado' => $request->estado ?? 'pendiente',
            ]);

            $investment->load(['user.generalData', 'vehicle', 'business']);

            // Formatear respuesta
            $formattedInvestment = [
                'id' => $investment->id,
                'user_id' => $investment->user_id,
                'vehicle_id' => $investment->vehicle_id,
                'business_id' => $investment->business_id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                        : 'Sin nombre',
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'vehicle' => $investment->vehicle,
                'business' => $investment->business,
                'monto_inversion' => floatval($investment->monto_inversion),
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => (bool)$investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $investment->updated_at?->format('Y-m-d H:i:s'),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión creada exitosamente',
                'data' => $formattedInvestment
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en InvestmentController@store: ' . $e->getMessage());

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

            // Formatear respuesta
            $formattedInvestment = [
                'id' => $investment->id,
                'user_id' => $investment->user_id,
                'vehicle_id' => $investment->vehicle_id,
                'business_id' => $investment->business_id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                        : 'Sin nombre',
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
                'monto_inversion' => floatval($investment->monto_inversion),
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => (bool)$investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $investment->updated_at?->format('Y-m-d H:i:s'),
            ];

            return response()->json([
                'success' => true,
                'data' => $formattedInvestment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@show: ' . $e->getMessage());
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

            // Formatear respuesta
            $formattedInvestment = [
                'id' => $investment->id,
                'user_id' => $investment->user_id,
                'vehicle_id' => $investment->vehicle_id,
                'business_id' => $investment->business_id,
                'inversionista' => [
                    'id' => $investment->user?->id,
                    'email' => $investment->user?->email,
                    'nombre_completo' => $investment->user?->generalData
                        ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                        : 'Sin nombre',
                    'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                    'celular' => $investment->user?->generalData?->celular,
                ],
                'vehicle' => $investment->vehicle,
                'business' => $investment->business,
                'monto_inversion' => floatval($investment->monto_inversion),
                'descripcion' => $investment->descripcion,
                'notas' => $investment->notas,
                'active' => (bool)$investment->active,
                'estado' => $investment->estado,
                'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $investment->updated_at?->format('Y-m-d H:i:s'),
            ];

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inversión actualizada exitosamente',
                'data' => $formattedInvestment
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en InvestmentController@update: ' . $e->getMessage());

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
            Log::error('Error en InvestmentController@destroy: ' . $e->getMessage());
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

            // Formatear inversiones
            $formattedInvestments = $investments->map(function ($investment) {
                return [
                    'id' => $investment->id,
                    'vehicle' => $investment->vehicle,
                    'business' => $investment->business,
                    'monto_inversion' => floatval($investment->monto_inversion),
                    'descripcion' => $investment->descripcion,
                    'notas' => $investment->notas,
                    'active' => (bool)$investment->active,
                    'estado' => $investment->estado,
                    'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'inversionista' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'nombre_completo' => $user->generalData
                        ? trim($user->generalData->nombre . ' ' . $user->generalData->apellido)
                        : 'Sin nombre',
                    'documento_identidad' => $user->generalData?->documento_identidad,
                    'celular' => $user->generalData?->celular,
                    'ciudad' => $user->generalData?->ciudad,
                ],
                'inversiones' => $formattedInvestments,
                'total_invertido' => floatval($total),
                'cantidad_inversiones' => $investments->count(),
                'inversiones_activas' => $investments->where('active', true)->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@byUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones del usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener inversiones de un negocio específico
     */
    public function byBusiness($businessId)
    {
        try {
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
                            ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                            : 'Sin nombre',
                        'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                        'celular' => $investment->user?->generalData?->celular,
                    ],
                    'vehicle' => $investment->vehicle,
                    'monto_inversion' => floatval($investment->monto_inversion),
                    'descripcion' => $investment->descripcion,
                    'active' => (bool)$investment->active,
                    'estado' => $investment->estado,
                    'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'business' => $business,
                'inversiones' => $formattedInvestments,
                'total_invertido' => floatval($total),
                'cantidad_inversionistas' => $investments->unique('user_id')->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@byBusiness: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener inversiones de un vehículo específico
     */
    public function byVehicle($vehicleId)
    {
        try {
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
                            ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                            : 'Sin nombre',
                        'documento_identidad' => $investment->user?->generalData?->documento_identidad,
                        'celular' => $investment->user?->generalData?->celular,
                    ],
                    'business' => $investment->business,
                    'monto_inversion' => floatval($investment->monto_inversion),
                    'descripcion' => $investment->descripcion,
                    'active' => (bool)$investment->active,
                    'estado' => $investment->estado,
                    'created_at' => $investment->created_at?->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'vehicle' => $vehicle,
                'inversiones' => $formattedInvestments,
                'total_invertido' => floatval($total),
                'cantidad_inversionistas' => $investments->unique('user_id')->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@byVehicle: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener inversiones del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado de la inversión
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

            $investment->load(['user.generalData', 'vehicle', 'business']);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado exitosamente',
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@changeStatus: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar el estado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activar/Desactivar inversión
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

            $investment->load(['user.generalData', 'vehicle', 'business']);

            return response()->json([
                'success' => true,
                'message' => $investment->active ? 'Inversión activada' : 'Inversión desactivada',
                'data' => $investment
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@toggleActive: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado activo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estadísticas generales de inversiones
     */
    public function statistics()
    {
        try {
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
                            ? trim($investment->user->generalData->nombre . ' ' . $investment->user->generalData->apellido)
                            : 'Sin nombre',
                        'email' => $investment->user?->email,
                        'total_invertido' => floatval($investment->total_invertido),
                    ];
                });

            return response()->json([
                'success' => true,
                'estadisticas' => [
                    'total_inversiones' => $totalInversiones,
                    'total_monto_invertido' => floatval($totalMonto),
                    'inversiones_activas' => $inversionesActivas,
                    'inversiones_por_estado' => $inversionesPorEstado,
                    'top_inversionistas' => $topInversionistas,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error en InvestmentController@statistics: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener estadísticas',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
