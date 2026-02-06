<?php

namespace App\Http\Controllers\api\InvestorLeaseOn;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Investment;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EstadosDeResultadoInvestorLeaseOn extends Controller
{
    /**
     * Obtener negocios donde el inversionista ha invertido
     */
    public function getMyBusinesses(): JsonResponse
    {
        try {
            $user = Auth::user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Obtener inversiones activas del usuario
            $inversiones = Investment::where('user_id', $user->id)
                ->where('active', true)
                ->with(['business:id,nombre,descripcion,estado'])
                ->get();

            if ($inversiones->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No tienes inversiones activas',
                    'data' => [],
                    'count' => 0
                ], 200);
            }

            // Agrupar por negocio y calcular totales
            $negociosData = $inversiones->groupBy('business_id')->map(function ($inversionesNegocio) {
                $negocio = $inversionesNegocio->first()->business;

                if (!$negocio) {
                    return null;
                }

                $totalInvertido = $inversionesNegocio->sum('monto_inversion');
                $cantidadInversiones = $inversionesNegocio->count();

                return [
                    'id' => $negocio->id,
                    'nombre' => strtoupper($negocio->nombre),
                    'descripcion' => $negocio->descripcion ?? 'Sin descripción',
                    'estado' => boolval($negocio->estado),
                    'estado_display' => $negocio->estado ? 'Activo' : 'Inactivo',
                    'inversion' => [
                        'total_invertido' => floatval($totalInvertido),
                        'total_invertido_formateado' => number_format($totalInvertido, 2, '.', ','),
                        'cantidad_inversiones' => $cantidadInversiones,
                    ],
                ];
            })->filter()->values();

            return response()->json([
                'success' => true,
                'message' => 'Negocios obtenidos exitosamente',
                'data' => $negociosData,
                'count' => $negociosData->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en getMyBusinesses: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los negocios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener vehículos donde el inversionista ha invertido en un negocio específico
     */
    public function getMyVehiclesByBusiness(Request $request): JsonResponse
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
                    'success' => false,
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $negocioId = $request->negocio_id;

            // Verificar que el usuario tiene inversiones en ese negocio
            $inversionEnNegocio = Investment::where('user_id', $user->id)
                ->where('business_id', $negocioId)
                ->where('active', true)
                ->exists();

            if (!$inversionEnNegocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes inversiones activas en este negocio'
                ], 403);
            }

            // Obtener vehículos donde el usuario ha invertido en ese negocio
            $inversiones = Investment::where('user_id', $user->id)
                ->where('business_id', $negocioId)
                ->where('active', true)
                ->whereNotNull('vehicle_id')
                ->with([
                    'vehicle' => function ($query) {
                        $query->select('id', 'codigo_unico', 'numero_placa', 'numero_vin', 'marca', 'modelo', 'año', 'color', 'tipo_vehiculo', 'tipo_propiedad', 'valor_actual', 'precio_compra', 'millaje', 'is_active', 'negocio_id')
                            ->where('is_active', true);
                    }
                ])
                ->get();

            if ($inversiones->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No tienes inversiones en vehículos de este negocio',
                    'data' => [],
                    'count' => 0
                ], 200);
            }

            // Formatear datos de vehículos con información de inversión
            $vehiculosData = $inversiones->map(function ($inversion) {
                $vehicle = $inversion->vehicle;

                if (!$vehicle) {
                    return null;
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
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                    'millaje' => intval($vehicle->millaje ?? 0),
                    'is_active' => boolval($vehicle->is_active),
                    'estado_display' => $vehicle->is_active ? 'Activo' : 'Inactivo',
                    'nombre_display' => trim("{$vehicle->numero_placa} - {$vehicle->marca} {$vehicle->modelo}"),
                    'nombre_completo' => trim("{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})"),
                    'inversion' => [
                        'id' => $inversion->id,
                        'monto_invertido' => floatval($inversion->monto_inversion),
                        'monto_invertido_formateado' => number_format($inversion->monto_inversion, 2, '.', ','),
                        'descripcion' => $inversion->descripcion,
                        'active' => boolval($inversion->active),
                        'estado_inversion' => $inversion->estado,
                    ]
                ];
            })->filter()->values();

            return response()->json([
                'success' => true,
                'message' => 'Vehículos obtenidos correctamente',
                'data' => $vehiculosData,
                'count' => $vehiculosData->count(),
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en getMyVehiclesByBusiness: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener los vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estado de resultados del inversionista
     */
    public function getFinancialStatementByDateRange(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
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

        $user = Auth::user();
        $negocioId = $request->negocio_id;
        $vehicleId = $request->vehicle_id;
        $fechaInicial = $request->fecha_inicial;
        $fechaFinal = $request->fecha_final;

        try {
            // ✅ VERIFICAR QUE EL USUARIO TIENE INVERSIONES ACTIVAS EN ESTE NEGOCIO
            $inversionQuery = Investment::where('user_id', $user->id)
                ->where('business_id', $negocioId)
                ->where('active', true);

            if ($vehicleId) {
                $inversionQuery->where('vehicle_id', $vehicleId);
            }

            $inversiones = $inversionQuery->get();

            if ($inversiones->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversiones activas en este ' . ($vehicleId ? 'vehículo' : 'negocio')
                ], 403);
            }

            $negocio = Business::findOrFail($negocioId);

            // Verificar que el negocio esté activo
            if (!$negocio->estado) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El negocio no está activo'
                ], 400);
            }

            $vehicle = null;
            $esFiltradoPorVehiculo = !is_null($vehicleId);

            if ($esFiltradoPorVehiculo) {
                $vehicle = Vehicle::findOrFail($vehicleId);

                if ($vehicle->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }

                // Verificar que el vehículo esté activo
                if (!$vehicle->is_active) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no está activo'
                    ], 400);
                }

                // Verificar que el usuario tiene inversión activa en este vehículo
                $tieneInversionEnVehiculo = Investment::where('user_id', $user->id)
                    ->where('business_id', $negocioId)
                    ->where('vehicle_id', $vehicleId)
                    ->where('active', true)
                    ->exists();

                if (!$tieneInversionEnVehiculo) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No tienes inversiones activas en este vehículo'
                    ], 403);
                }
            }

            // ✅ OBTENER TRANSACCIONES FINANCIERAS
            $queryIngresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            $queryEgresos = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryIngresos->where('vehicle_id', $vehicleId);
                $queryEgresos->where('vehicle_id', $vehicleId);
            }

            $totalIngresosBrutos = $queryIngresos->sum('importe_total');
            $totalEgresosBrutos = $queryEgresos->sum('importe_total');
            $margenBruto = $totalIngresosBrutos - $totalEgresosBrutos;

            // Calcular rentabilidad
            $totalInvertido = $inversiones->sum('monto_inversion');
            $rentabilidadPorcentaje = $totalInvertido > 0 ? ($margenBruto / $totalInvertido) * 100 : 0;

            // ✅ RESUMEN POR ESTADO
            $queryTransaccionesEstado = FinancialTransactions::where('negocio_id', $negocioId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal]);

            if ($esFiltradoPorVehiculo) {
                $queryTransaccionesEstado->where('vehicle_id', $vehicleId);
            }

            $transaccionesPorEstado = $queryTransaccionesEstado
                ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                ->select(
                    'transaction_states.id as estado_id',
                    'transaction_states.nombre as estado_nombre',
                    DB::raw('COALESCE(transaction_states.descripcion, "") as estado_descripcion'),
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre', 'transaction_states.descripcion', 'financial_transactions.tipo_de_transaccion')
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
                        'estado_descripcion' => $transaccion->estado_descripcion ?? '',
                        'ingresos' => 0,
                        'egresos' => 0,
                        'total_transacciones_ingresos' => 0,
                        'total_transacciones_egresos' => 0,
                        'balance_estado' => 0,
                    ];
                }

                if ($tipo === 'Ingreso') {
                    $estadosFinancieros[$estadoId]['ingresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                } else {
                    $estadosFinancieros[$estadoId]['egresos'] = floatval($transaccion->total_importe);
                    $estadosFinancieros[$estadoId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                }

                $estadosFinancieros[$estadoId]['balance_estado'] = $estadosFinancieros[$estadoId]['ingresos'] - $estadosFinancieros[$estadoId]['egresos'];
            }

            // ✅ RESUMEN POR CATEGORÍA
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
                            $categoriaData['total_ingresos'] = $item->total;
                            $categoriaData['cantidad_ingresos'] = $item->cantidad;
                        } else {
                            $categoriaData['total_egresos'] = $item->total;
                            $categoriaData['cantidad_egresos'] = $item->cantidad;
                        }
                    }

                    $categoriaData['balance_categoria'] = $categoriaData['total_ingresos'] - $categoriaData['total_egresos'];
                    return $categoriaData;
                })
                ->filter(function ($categoria) {
                    $totalIngresos = floatval($categoria['total_ingresos']);
                    $totalEgresos = floatval($categoria['total_egresos']);
                    $cantidadTotal = intval($categoria['cantidad_ingresos']) + intval($categoria['cantidad_egresos']);
                    return $cantidadTotal > 0 || $totalIngresos > 0 || $totalEgresos > 0;
                });

            $formatoMoneda = function ($valor) {
                return number_format($valor, 2, '.', ',');
            };

            $responseData = [
                'negocio' => [
                    'id' => $negocioId,
                    'nombre' => strtoupper($negocio->nombre),
                    'estado' => boolval($negocio->estado),
                ],
                'periodo' => [
                    'fecha_inicial' => $fechaInicial,
                    'fecha_final' => $fechaFinal,
                    'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1,
                ],
                'filtro' => [
                    'por_vehiculo' => $esFiltradoPorVehiculo,
                    'vehicle_id' => $vehicleId,
                ],
                'inversion' => [
                    'total_invertido' => floatval($totalInvertido),
                    'total_invertido_formateado' => $formatoMoneda($totalInvertido),
                    'cantidad_inversiones' => $inversiones->count(),
                ],
                'resumen_financiero' => [
                    'total_ingresos_brutos' => $formatoMoneda($totalIngresosBrutos),
                    'total_ingresos_brutos_raw' => floatval($totalIngresosBrutos),
                    'total_egresos_brutos' => $formatoMoneda($totalEgresosBrutos),
                    'total_egresos_brutos_raw' => floatval($totalEgresosBrutos),
                    'margen_bruto' => $formatoMoneda($margenBruto),
                    'margen_bruto_raw' => floatval($margenBruto),
                    'rentabilidad_porcentaje' => number_format($rentabilidadPorcentaje, 2, '.', ','),
                    'rentabilidad_porcentaje_raw' => floatval($rentabilidadPorcentaje),
                    'roi' => $totalInvertido > 0 ? number_format(($margenBruto / $totalInvertido) * 100, 2, '.', ',') : '0.00',
                ],
                'detalle_por_estado' => array_values($estadosFinancieros),
                'resumen_por_categoria' => $resumenPorCategoria->values()->all(),
            ];

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
                    'is_active' => boolval($vehicle->is_active),
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => $esFiltradoPorVehiculo
                    ? 'Estado financiero del vehículo generado exitosamente'
                    : 'Estado financiero del negocio generado exitosamente',
                'datos' => $responseData,
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en getFinancialStatementByDateRange: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el estado financiero',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de inversiones del usuario
     */
    public function getMyInvestmentsSummary(): JsonResponse
    {
        try {
            $user = Auth::user();

            $inversiones = Investment::where('user_id', $user->id)
                ->where('active', true)
                ->with(['business:id,nombre,estado', 'vehicle:id,codigo_unico,numero_placa,marca,modelo,is_active'])
                ->get();

            if ($inversiones->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No tienes inversiones activas',
                    'data' => [
                        'total_invertido' => 0,
                        'cantidad_inversiones' => 0,
                        'cantidad_negocios' => 0,
                        'cantidad_vehiculos' => 0,
                        'inversiones' => []
                    ]
                ], 200);
            }

            $totalInvertido = $inversiones->sum('monto_inversion');
            $cantidadNegocios = $inversiones->unique('business_id')->count();
            $cantidadVehiculos = $inversiones->whereNotNull('vehicle_id')->unique('vehicle_id')->count();

            $inversionesData = $inversiones->map(function ($inversion) {
                return [
                    'id' => $inversion->id,
                    'monto_inversion' => floatval($inversion->monto_inversion),
                    'monto_inversion_formateado' => number_format($inversion->monto_inversion, 2, '.', ','),
                    'descripcion' => $inversion->descripcion,
                    'active' => boolval($inversion->active),
                    'estado_inversion' => $inversion->estado,
                    'negocio' => [
                        'id' => $inversion->business->id,
                        'nombre' => strtoupper($inversion->business->nombre),
                        'estado' => boolval($inversion->business->estado),
                    ],
                    'vehiculo' => $inversion->vehicle ? [
                        'id' => $inversion->vehicle->id,
                        'codigo_unico' => $inversion->vehicle->codigo_unico,
                        'numero_placa' => $inversion->vehicle->numero_placa,
                        'nombre_completo' => trim("{$inversion->vehicle->codigo_unico} - {$inversion->vehicle->numero_placa} ({$inversion->vehicle->marca} {$inversion->vehicle->modelo})"),
                        'is_active' => boolval($inversion->vehicle->is_active),
                    ] : null,
                    'fecha_creacion' => $inversion->created_at ? $inversion->created_at->format('Y-m-d H:i:s') : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Resumen de inversiones obtenido exitosamente',
                'data' => [
                    'total_invertido' => floatval($totalInvertido),
                    'total_invertido_formateado' => number_format($totalInvertido, 2, '.', ','),
                    'cantidad_inversiones' => $inversiones->count(),
                    'cantidad_negocios' => $cantidadNegocios,
                    'cantidad_vehiculos' => $cantidadVehiculos,
                    'inversiones' => $inversionesData
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en getMyInvestmentsSummary: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen de inversiones',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
