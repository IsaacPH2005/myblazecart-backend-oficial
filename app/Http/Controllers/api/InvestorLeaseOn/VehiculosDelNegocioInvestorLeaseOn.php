<?php

namespace App\Http\Controllers\api\InvestorLeaseOn;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Investment;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VehiculosDelNegocioInvestorLeaseOn extends Controller
{
    /**
     * ============================================================================
     * OBTENER VEHÍCULOS DEL INVERSIONISTA POR NEGOCIO
     * ============================================================================
     */
    public function getMyVehiclesByBusiness(Request $request)
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

            $user = Auth::user();
            $negocioId = $request->input('negocio_id');

            // Verificar que el usuario tiene inversiones en el negocio
            $tieneInversion = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->exists();

            if (!$tieneInversion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversiones activas en este negocio',
                ], 403);
            }

            // Obtener IDs de vehículos donde tiene inversión
            $vehiculosConInversion = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->toArray();

            if (empty($vehiculosConInversion)) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No tienes inversiones en vehículos específicos de este negocio',
                    'data' => [],
                    'total' => 0,
                ], 200);
            }

            // Obtener información de los vehículos
            $vehicles = Vehicle::whereIn('id', $vehiculosConInversion)
                ->where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData'])
                ->orderBy('tipo_propiedad')
                ->orderBy('codigo_unico')
                ->get();

            // Obtener información de inversión por vehículo
            $inversiones = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->whereIn('vehicle_id', $vehiculosConInversion)
                ->get()
                ->keyBy('vehicle_id');

            $vehiculosData = $vehicles->map(function ($vehicle) use ($inversiones) {
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
                }

                $inversion = $inversiones->get($vehicle->id);

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
                    'inversion' => $inversion ? [
                        'id' => $inversion->id,
                        'monto_invertido' => floatval($inversion->monto_inversion),
                        'monto_invertido_formateado' => number_format($inversion->monto_inversion, 2, '.', ','),
                        'fecha_inversion' => $inversion->created_at->format('Y-m-d'),
                        'descripcion' => $inversion->descripcion,
                        'estado' => $inversion->estado,
                    ] : null,
                    'nombre_display' => trim("{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})")
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Vehículos con inversión obtenidos correctamente',
                'data' => $vehiculosData->toArray(),
                'total' => $vehiculosData->count(),
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los vehículos',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================================
     * ESTADO FINANCIERO DE VEHÍCULOS DEL INVERSIONISTA
     * ============================================================================
     */
    public function getMyVehiclesFinancialStatement(Request $request)
    {
        try {
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
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $negocioId = $request->input('negocio_id');
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Verificar inversión en el negocio
            $tieneInversion = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->exists();

            if (!$tieneInversion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversiones activas en este negocio',
                ], 403);
            }

            $negocio = Business::findOrFail($negocioId);

            // Obtener IDs de vehículos donde tiene inversión
            $vehiculosConInversionQuery = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->whereNotNull('vehicle_id');

            if ($vehicleId) {
                $vehiculosConInversionQuery->where('vehicle_id', $vehicleId);

                // Verificar que el vehículo pertenece al negocio
                $vehiculo = Vehicle::find($vehicleId);
                if ($vehiculo && $vehiculo->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }
            }

            $vehiculosConInversion = $vehiculosConInversionQuery->pluck('vehicle_id')->toArray();

            if (empty($vehiculosConInversion)) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No se encontraron vehículos con inversión',
                    'data' => [
                        'negocio' => [
                            'id' => $negocio->id,
                            'nombre' => strtoupper($negocio->nombre)
                        ],
                        'periodo' => [
                            'fecha_inicial' => $fechaInicial,
                            'fecha_final' => $fechaFinal,
                            'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                        ],
                        'vehicles' => []
                    ]
                ], 200);
            }

            // Obtener vehículos
            $vehicles = Vehicle::whereIn('id', $vehiculosConInversion)
                ->where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData'])
                ->orderBy('tipo_propiedad')
                ->orderBy('codigo_unico')
                ->get();

            // Obtener inversiones
            $inversiones = Investment::where('business_id', $negocioId)
                ->where('user_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->whereIn('vehicle_id', $vehiculosConInversion)
                ->get()
                ->keyBy('vehicle_id');

            $vehiclesFinancialData = [];
            $totalGeneralIngresos = 0;
            $totalGeneralEgresos = 0;
            $totalGeneralInversion = 0;

            foreach ($vehicles as $vehicle) {
                // Calcular ingresos (sin caja operativa)
                $totalIngresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                // Calcular egresos
                $totalEgresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                $margenBruto = $totalIngresos - $totalEgresos;
                $rentabilidad = $totalIngresos > 0 ? ($margenBruto / $totalIngresos) * 100 : 0;

                // Contar transacciones
                $totalTransaccionesIngresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->count();

                $totalTransaccionesEgresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->count();

                // Transacciones por estado
                $transaccionesPorEstado = FinancialTransactions::where('vehicle_id', $vehicle->id)
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
                        'transaction_states.descripcion as estado_descripcion',
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

                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
                }

                $inversion = $inversiones->get($vehicle->id);
                $montoInvertido = $inversion ? floatval($inversion->monto_inversion) : 0;
                $roiPorcentaje = $montoInvertido > 0 ? ($margenBruto / $montoInvertido) * 100 : 0;

                $vehiclesFinancialData[] = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_info' => [
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
                    ],
                    'mi_inversion' => [
                        'monto_invertido' => number_format($montoInvertido, 2, '.', ','),
                        'monto_invertido_raw' => $montoInvertido,
                        'fecha_inversion' => $inversion ? $inversion->created_at->format('Y-m-d') : null,
                        'descripcion' => $inversion ? $inversion->descripcion : null,
                        'roi_porcentaje' => number_format($roiPorcentaje, 2, '.', ''),
                        'roi_porcentaje_raw' => floatval($roiPorcentaje),
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2, '.', ','),
                        'total_ingresos_raw' => floatval($totalIngresos),
                        'total_egresos' => number_format($totalEgresos, 2, '.', ','),
                        'total_egresos_raw' => floatval($totalEgresos),
                        'margen_bruto' => number_format($margenBruto, 2, '.', ','),
                        'margen_bruto_raw' => floatval($margenBruto),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidad),
                        'total_transacciones_ingresos' => intval($totalTransaccionesIngresos),
                        'total_transacciones_egresos' => intval($totalTransaccionesEgresos),
                        'total_transacciones' => intval($totalTransaccionesIngresos + $totalTransaccionesEgresos),
                    ],
                    'detalle_por_estado' => array_values($estadosFinancieros)
                ];

                $totalGeneralIngresos += $totalIngresos;
                $totalGeneralEgresos += $totalEgresos;
                $totalGeneralInversion += $montoInvertido;
            }

            $totalGeneralMargen = $totalGeneralIngresos - $totalGeneralEgresos;
            $rentabilidadGeneral = $totalGeneralIngresos > 0 ? ($totalGeneralMargen / $totalGeneralIngresos) * 100 : 0;
            $roiGeneral = $totalGeneralInversion > 0 ? ($totalGeneralMargen / $totalGeneralInversion) * 100 : 0;

            $vehiclesByType = collect($vehiclesFinancialData)->groupBy(function ($item) {
                return $item['vehicle_info']['tipo_propiedad'];
            })->map(function ($group, $tipo) {
                $totalIngresosTipo = $group->sum('resumen_financiero.total_ingresos_raw');
                $totalEgresosTipo = $group->sum('resumen_financiero.total_egresos_raw');
                $totalInversionTipo = $group->sum('mi_inversion.monto_invertido_raw');
                $margenTipo = $totalIngresosTipo - $totalEgresosTipo;
                $rentabilidadTipo = $totalIngresosTipo > 0 ? ($margenTipo / $totalIngresosTipo) * 100 : 0;
                $roiTipo = $totalInversionTipo > 0 ? ($margenTipo / $totalInversionTipo) * 100 : 0;

                return [
                    'tipo_propiedad' => $tipo,
                    'cantidad_vehiculos' => $group->count(),
                    'resumen_tipo' => [
                        'total_inversion' => number_format($totalInversionTipo, 2, '.', ','),
                        'total_inversion_raw' => floatval($totalInversionTipo),
                        'total_ingresos' => number_format($totalIngresosTipo, 2, '.', ','),
                        'total_ingresos_raw' => floatval($totalIngresosTipo),
                        'total_egresos' => number_format($totalEgresosTipo, 2, '.', ','),
                        'total_egresos_raw' => floatval($totalEgresosTipo),
                        'margen_bruto' => number_format($margenTipo, 2, '.', ','),
                        'margen_bruto_raw' => floatval($margenTipo),
                        'rentabilidad_porcentaje' => number_format($rentabilidadTipo, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidadTipo),
                        'roi_porcentaje' => number_format($roiTipo, 2, '.', ''),
                        'roi_porcentaje_raw' => floatval($roiTipo),
                    ],
                    'vehiculos' => $group->values()->toArray()
                ];
            });

            $response = [
                'status' => 'success',
                'message' => $vehicleId
                    ? 'Estado financiero del vehículo obtenido correctamente'
                    : 'Estado financiero de tus vehículos obtenido correctamente',
                'data' => [
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => strtoupper($negocio->nombre)
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                    ],
                    'filtros' => [
                        'vehicle_id' => $vehicleId,
                        'filtrado_por_vehiculo' => !is_null($vehicleId),
                    ],
                    'mi_inversion_total' => [
                        'monto_total_invertido' => number_format($totalGeneralInversion, 2, '.', ','),
                        'monto_total_invertido_raw' => floatval($totalGeneralInversion),
                        'cantidad_vehiculos_invertidos' => $vehicles->count(),
                        'roi_general_porcentaje' => number_format($roiGeneral, 2, '.', ''),
                        'roi_general_porcentaje_raw' => floatval($roiGeneral),
                    ],
                    'resumen_general' => [
                        'total_ingresos' => number_format($totalGeneralIngresos, 2, '.', ','),
                        'total_ingresos_raw' => floatval($totalGeneralIngresos),
                        'total_egresos' => number_format($totalGeneralEgresos, 2, '.', ','),
                        'total_egresos_raw' => floatval($totalGeneralEgresos),
                        'margen_bruto' => number_format($totalGeneralMargen, 2, '.', ','),
                        'margen_bruto_raw' => floatval($totalGeneralMargen),
                        'rentabilidad_porcentaje' => number_format($rentabilidadGeneral, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidadGeneral),
                        'cantidad_vehiculos' => $vehicles->count(),
                        'cantidad_tipos_propiedad' => $vehiclesByType->count(),
                    ],
                    'agrupado_por_tipo' => $vehiclesByType->values()->toArray(),
                    'vehicles' => $vehiclesFinancialData
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero de vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ============================================================================
     * ESTADO FINANCIERO DETALLADO DE UN VEHÍCULO ESPECÍFICO
     * ============================================================================
     */
    public function getMyVehicleDetailedFinancialStatement(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|exists:vehicles,id',
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
            ], [
                'vehicle_id.required' => 'El ID del vehículo es obligatorio',
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
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $user = Auth::user();
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            $vehicle = Vehicle::with(['negocio', 'user.generalData'])->find($vehicleId);

            if (!$vehicle) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vehículo no encontrado'
                ], 404);
            }

            // Verificar inversión en el vehículo
            $inversion = Investment::where('business_id', $vehicle->negocio_id)
                ->where('user_id', $user->id)
                ->where('vehicle_id', $vehicleId)
                ->where('estado', 'ACTIVO')
                ->first();

            if (!$inversion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversión activa en este vehículo',
                ], 403);
            }

            // Calcular ingresos
            $totalIngresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            // Calcular egresos
            $totalEgresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            $margenBruto = $totalIngresos - $totalEgresos;
            $rentabilidad = $totalIngresos > 0 ? ($margenBruto / $totalIngresos) * 100 : 0;
            $montoInvertido = floatval($inversion->monto_inversion);
            $roiPorcentaje = $montoInvertido > 0 ? ($margenBruto / $montoInvertido) * 100 : 0;

            // Transacciones por estado
            $transaccionesPorEstado = FinancialTransactions::where('vehicle_id', $vehicleId)
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
                    'transaction_states.descripcion as estado_descripcion',
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

            // Transacciones por categoría
            $transaccionesPorCategoria = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->join('categories', 'financial_transactions.categoria_id', '=', 'categories.id')
                ->select(
                    'categories.id as categoria_id',
                    'categories.nombre as categoria_nombre',
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('categories.id', 'categories.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get();

            $categoriasFinancieras = [];
            foreach ($transaccionesPorCategoria as $transaccion) {
                $categoriaId = $transaccion->categoria_id;
                $categoriaNombre = strtoupper($transaccion->categoria_nombre);
                $tipo = $transaccion->tipo_de_transaccion;

                if (!isset($categoriasFinancieras[$categoriaId])) {
                    $categoriasFinancieras[$categoriaId] = [
                        'categoria_id' => $categoriaId,
                        'categoria_nombre' => $categoriaNombre,
                        'ingresos' => 0,
                        'egresos' => 0,
                        'total_transacciones_ingresos' => 0,
                        'total_transacciones_egresos' => 0,
                        'balance_categoria' => 0
                    ];
                }

                if ($tipo === 'Ingreso') {
                    $categoriasFinancieras[$categoriaId]['ingresos'] = floatval($transaccion->total_importe);
                    $categoriasFinancieras[$categoriaId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                } else {
                    $categoriasFinancieras[$categoriaId]['egresos'] = floatval($transaccion->total_importe);
                    $categoriasFinancieras[$categoriaId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                }

                $categoriasFinancieras[$categoriaId]['balance_categoria'] =
                    $categoriasFinancieras[$categoriaId]['ingresos'] - $categoriasFinancieras[$categoriaId]['egresos'];
            }

            // Obtener transacciones
            $transacciones = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where(function ($query) {
                    $query->where('tipo_de_transaccion', 'Egreso')
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'estadoDeTransaccion', 'user.generalData'])
                ->orderBy('fecha', 'desc')
                ->get();

            $assignedUserName = 'Sin asignar';
            if ($vehicle->user && $vehicle->user->generalData) {
                $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                    $vehicle->user->generalData->apellido;
            }

            $response = [
                'status' => 'success',
                'message' => 'Estado financiero detallado del vehículo obtenido correctamente',
                'data' => [
                    'vehicle' => [
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
                        'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                        'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                        'millaje' => intval($vehicle->millaje ?? 0),
                    ],
                    'negocio' => [
                        'id' => $vehicle->negocio->id,
                        'nombre' => strtoupper($vehicle->negocio->nombre)
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                    ],
                    'mi_inversion' => [
                        'id' => $inversion->id,
                        'monto_invertido' => number_format($montoInvertido, 2, '.', ','),
                        'monto_invertido_raw' => $montoInvertido,
                        'fecha_inversion' => $inversion->created_at->format('Y-m-d'),
                        'descripcion' => $inversion->descripcion,
                        'roi_porcentaje' => number_format($roiPorcentaje, 2, '.', ''),
                        'roi_porcentaje_raw' => floatval($roiPorcentaje),
                        'estado' => $inversion->estado,
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2, '.', ','),
                        'total_ingresos_raw' => floatval($totalIngresos),
                        'total_egresos' => number_format($totalEgresos, 2, '.', ','),
                        'total_egresos_raw' => floatval($totalEgresos),
                        'margen_bruto' => number_format($margenBruto, 2, '.', ','),
                        'margen_bruto_raw' => floatval($margenBruto),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidad),
                        'total_transacciones' => $transacciones->count()
                    ],
                    'detalle_por_estado' => array_values($estadosFinancieros),
                    'detalle_por_categoria' => array_values($categoriasFinancieras),
                    'transacciones' => $transacciones->map(function ($transaccion) {
                        $registeredUserName = 'N/A';
                        if ($transaccion->user && $transaccion->user->generalData) {
                            $registeredUserName = $transaccion->user->generalData->nombre . ' ' .
                                $transaccion->user->generalData->apellido;
                        }
                        return [
                            'id' => $transaccion->id,
                            'fecha' => $transaccion->fecha,
                            'item' => $transaccion->item,
                            'cantidad' => $transaccion->cantidad,
                            'importe_total' => number_format($transaccion->importe_total, 2, '.', ','),
                            'importe_total_raw' => floatval($transaccion->importe_total),
                            'tipo_de_transaccion' => $transaccion->tipo_de_transaccion,
                            'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                            'estado' => $transaccion->estadoDeTransaccion->nombre ?? 'Sin estado',
                            'usuario_registro' => $registeredUserName,
                            'observaciones' => $transaccion->observaciones ?? '',
                            'archivo' => $transaccion->archivo ? asset($transaccion->archivo) : null
                        ];
                    })
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
