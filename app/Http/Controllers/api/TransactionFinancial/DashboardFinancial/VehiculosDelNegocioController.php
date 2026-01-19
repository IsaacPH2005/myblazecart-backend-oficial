<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class VehiculosDelNegocioController extends Controller
{
    /**
     * ============================================================================
     * 1. OBTENER TODOS LOS VEHÍCULOS DE UN NEGOCIO
     * ============================================================================
     * Endpoint simplificado que retorna TODOS los vehículos de un negocio
     * sin necesidad de filtrar por tipo de propiedad primero
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    /**
     * Obtener todos los vehículos de un negocio
     */
    public function getVehiclesByBusiness(Request $request)
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

            $negocioId = $request->input('negocio_id');

            // Primero verificar si hay vehículos SIN el filtro de is_active
            $totalVehicles = Vehicle::where('negocio_id', $negocioId)->count();
            $activeVehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->count();

            Log::info('📊 Conteo de vehículos', [
                'negocio_id' => $negocioId,
                'total_vehiculos' => $totalVehicles,
                'vehiculos_activos' => $activeVehicles
            ]);

            // Obtener vehículos activos
            $vehicles = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData'])
                ->orderBy('tipo_propiedad')
                ->orderBy('codigo_unico')
                ->get();

            if ($vehicles->isEmpty()) {
                // Si no hay vehículos activos, intentar obtener todos
                $allVehicles = Vehicle::where('negocio_id', $negocioId)->get();

                return response()->json([
                    'status' => 'success',
                    'message' => 'No hay vehículos activos para este negocio',
                    'datos' => [],
                    'total' => 0,
                    'debug' => [
                        'total_vehiculos_db' => $totalVehicles,
                        'vehiculos_activos' => $activeVehicles,
                        'vehiculos_inactivos' => $allVehicles->pluck('codigo_unico')
                    ]
                ], 200);
            }

            $vehiculosData = $vehicles->map(function ($vehicle) {
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
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
                    'usuario_asignado' => $assignedUserName,
                    'usuario_asignado_id' => $vehicle->user_id,
                    'valor_actual' => floatval($vehicle->valor_actual ?? 0),
                    'precio_compra' => floatval($vehicle->precio_compra ?? 0),
                    'millaje' => intval($vehicle->millaje ?? 0),
                    'is_active' => $vehicle->estado,
                    'nombre_display' => trim("{$vehicle->codigo_unico} - {$vehicle->numero_placa} ({$vehicle->marca} {$vehicle->modelo})")
                ];
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Vehículos obtenidos correctamente',
                'datos' => $vehiculosData->toArray(),
                'total' => $vehiculosData->count(),
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener los vehículos',
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    /**
     * ============================================================================
     * 2. ESTADO FINANCIERO DE VEHÍCULOS DEL NEGOCIO (CON FILTRO OPCIONAL DE VEHÍCULO)
     * ============================================================================
     * Este método maneja 2 casos:
     * 1. Estado financiero de TODOS los vehículos del negocio
     * 2. Estado financiero de un VEHÍCULO ESPECÍFICO (opcional)
     *
     * PARÁMETROS:
     * - negocio_id: ID del negocio (obligatorio)
     * - vehicle_id: ID del vehículo específico (opcional)
     * - fecha_inicial: Fecha de inicio (obligatorio)
     * - fecha_final: Fecha de fin (obligatorio)
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehiclesFinancialStatementByBusiness(Request $request)
    {
        try {
            // Validar parámetros
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

            $negocioId = $request->input('negocio_id');
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Obtener información del negocio
            $negocio = Business::findOrFail($negocioId);

            // Construir query base para vehículos
            $vehiclesQuery = Vehicle::where('negocio_id', $negocioId)
                ->where('is_active', true)
                ->with(['user.generalData']);

            // APLICAR FILTRO OPCIONAL DE VEHÍCULO
            if ($vehicleId) {
                $vehiclesQuery->where('id', $vehicleId);

                // Verificar que el vehículo pertenece al negocio
                $vehiculo = Vehicle::find($vehicleId);
                if ($vehiculo && $vehiculo->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }
            }

            // Ejecutar query
            $vehicles = $vehiclesQuery->orderBy('tipo_propiedad')->orderBy('codigo_unico')->get();

            if ($vehicles->isEmpty()) {
                return response()->json([
                    'status' => 'warning',
                    'message' => 'No se encontraron vehículos con los filtros especificados',
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
                        'vehicles' => []
                    ]
                ], 200);
            }

            // Array para almacenar los resultados por vehículo
            $vehiclesFinancialData = [];

            // Variables para los totales generales
            $totalGeneralIngresos = 0;
            $totalGeneralEgresos = 0;
            $totalGeneralMargen = 0;

            // Procesar cada vehículo
            foreach ($vehicles as $vehicle) {
                // Calcular totales de ingresos para el vehículo (EXCLUIR ingresos de caja operativa)
                $totalIngresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                // Calcular totales de egresos para el vehículo (TODOS los egresos)
                $totalEgresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                // Calcular margen bruto
                $margenBruto = $totalIngresos - $totalEgresos;

                // Calcular rentabilidad
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

                // Obtener transacciones por estado para el vehículo
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

                // Organizar datos por estado
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

                // Verificar si el vehículo tiene un usuario asignado
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                        $vehicle->user->generalData->apellido;
                }

                // Agregar datos del vehículo al array de resultados
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

                // Acumular totales generales
                $totalGeneralIngresos += $totalIngresos;
                $totalGeneralEgresos += $totalEgresos;
            }

            // Calcular margen general
            $totalGeneralMargen = $totalGeneralIngresos - $totalGeneralEgresos;
            $rentabilidadGeneral = $totalGeneralIngresos > 0 ? ($totalGeneralMargen / $totalGeneralIngresos) * 100 : 0;

            // AGRUPAR VEHÍCULOS POR TIPO DE PROPIEDAD
            $vehiclesByType = collect($vehiclesFinancialData)->groupBy(function ($item) {
                return $item['vehicle_info']['tipo_propiedad'];
            })->map(function ($group, $tipo) {
                $totalIngresosTipo = $group->sum('resumen_financiero.total_ingresos_raw');
                $totalEgresosTipo = $group->sum('resumen_financiero.total_egresos_raw');
                $margenTipo = $totalIngresosTipo - $totalEgresosTipo;
                $rentabilidadTipo = $totalIngresosTipo > 0 ? ($margenTipo / $totalIngresosTipo) * 100 : 0;

                return [
                    'tipo_propiedad' => $tipo,
                    'cantidad_vehiculos' => $group->count(),
                    'resumen_tipo' => [
                        'total_ingresos' => number_format($totalIngresosTipo, 2, '.', ','),
                        'total_ingresos_raw' => floatval($totalIngresosTipo),
                        'total_egresos' => number_format($totalEgresosTipo, 2, '.', ','),
                        'total_egresos_raw' => floatval($totalEgresosTipo),
                        'margen_bruto' => number_format($margenTipo, 2, '.', ','),
                        'margen_bruto_raw' => floatval($margenTipo),
                        'rentabilidad_porcentaje' => number_format($rentabilidadTipo, 2, '.', ''),
                        'rentabilidad_porcentaje_raw' => floatval($rentabilidadTipo),
                    ],
                    'vehiculos' => $group->values()->toArray()
                ];
            });

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => $vehicleId
                    ? 'Estado financiero del vehículo obtenido correctamente'
                    : 'Estado financiero de vehículos del negocio obtenido correctamente',
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

            Log::info('Estado financiero de vehículos generado', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'total_vehiculos' => $vehicles->count(),
                'total_ingresos' => $totalGeneralIngresos,
                'total_egresos' => $totalGeneralEgresos,
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener estado financiero de vehículos', [
                'negocio_id' => $request->input('negocio_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero de vehículos del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ============================================================================
     * 3. ESTADO FINANCIERO DE UN VEHÍCULO ESPECÍFICO (DETALLADO)
     * ============================================================================
     * Obtiene el estado financiero completo de un vehículo específico,
     * incluyendo transacciones individuales, detalles por estado y por categoría
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehicleFinancialStatement(Request $request)
    {
        try {
            // Validar parámetros
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

            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Obtener información del vehículo
            $vehicle = Vehicle::with(['negocio', 'user.generalData'])->find($vehicleId);

            if (!$vehicle) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Vehículo no encontrado'
                ], 404);
            }

            // Calcular totales de ingresos para el vehículo (EXCLUIR ingresos de caja operativa)
            $totalIngresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            // Calcular totales de egresos para el vehículo (TODOS los egresos)
            $totalEgresos = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->sum('importe_total');

            // Calcular margen bruto
            $margenBruto = $totalIngresos - $totalEgresos;

            // Calcular rentabilidad
            $rentabilidad = $totalIngresos > 0 ? ($margenBruto / $totalIngresos) * 100 : 0;

            // Obtener transacciones por estado para el vehículo
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

            // Organizar datos por estado
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

            // Obtener transacciones por categoría para el vehículo
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

            // Organizar datos por categoría
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

            // Obtener todas las transacciones del vehículo en el período
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

            // Verificar si el vehículo tiene un usuario asignado
            $assignedUserName = 'Sin asignar';
            if ($vehicle->user && $vehicle->user->generalData) {
                $assignedUserName = $vehicle->user->generalData->nombre . ' ' .
                    $vehicle->user->generalData->apellido;
            }

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Estado financiero del vehículo obtenido correctamente',
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

            Log::info('Estado financiero de vehículo individual generado', [
                'vehicle_id' => $vehicleId,
                'total_transacciones' => $transacciones->count(),
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener estado financiero del vehículo', [
                'vehicle_id' => $request->input('vehicle_id'),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
