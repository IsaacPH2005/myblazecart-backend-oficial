<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VehiculosDelNegocioController extends Controller
{
    /**
     * Obtener estado financiero de los vehículos de un negocio en un rango de fechas
     *
     * @param Request $request
     */
    public function getVehiclesFinancialStatementByBusiness(Request $request)
    {
        try {
            // Validar parámetros
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $negocioId = $request->input('negocio_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Obtener información del negocio
            $negocio = Business::find($negocioId);

            // Obtener los vehículos del negocio
            $vehicles = Vehicle::where('negocio_id', $negocioId)
                ->with(['user.generalData'])
                ->get();

            // Array para almacenar los resultados por vehículo
            $vehiclesFinancialData = [];

            // Variables para los totales generales del negocio
            $totalGeneralIngresos = 0;
            $totalGeneralEgresos = 0;
            $totalGeneralMargen = 0;

            foreach ($vehicles as $vehicle) {
                // Calcular totales de ingresos para el vehículo (EXCLUIR ingresos de caja operativa)
                $totalIngresos = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
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

                // Obtener transacciones por estado para el vehículo (con regla global: ingresos sin caja + todos egresos)
                $transaccionesPorEstado = FinancialTransactions::where('vehicle_id', $vehicle->id)
                    ->where(function ($query) {
                        // Todos los egresos
                        $query->where('tipo_de_transaccion', 'Egreso')
                            // O ingresos sin caja operativa
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
                        'financial_transactions.tipo_de_transaccion',
                        DB::raw('COUNT(*) as total_transacciones'),
                        DB::raw('SUM(importe_total) as total_importe')
                    )
                    ->groupBy('transaction_states.id', 'transaction_states.nombre', 'financial_transactions.tipo_de_transaccion')
                    ->get();

                // Organizar datos por estado
                $estadosFinancieros = [];
                foreach ($transaccionesPorEstado as $transaccion) {
                    $estadoId = $transaccion->estado_id;
                    $estadoNombre = $transaccion->estado_nombre;
                    $tipo = $transaccion->tipo_de_transaccion;

                    if (!isset($estadosFinancieros[$estadoId])) {
                        $estadosFinancieros[$estadoId] = [
                            'estado_id' => $estadoId,
                            'estado_nombre' => $estadoNombre,
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

                // Verificar si el vehículo tiene un usuario asignado con datos generales
                $assignedUserName = 'Sin asignar';
                if ($vehicle->user && $vehicle->user->generalData) {
                    $assignedUserName = $vehicle->user->generalData->nombre . ' ' . $vehicle->user->generalData->apellido;
                }

                // Agregar datos del vehículo al array de resultados
                $vehiclesFinancialData[] = [
                    'vehicle_id' => $vehicle->id,
                    'vehicle_info' => [
                        'marca' => $vehicle->marca,
                        'modelo' => $vehicle->modelo,
                        'numero_placa' => $vehicle->numero_placa,
                        'anio' => $vehicle->anio,
                        'color' => $vehicle->color,
                        'usuario_asignado' => $assignedUserName
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2),
                        'total_egresos' => number_format($totalEgresos, 2),
                        'margen_bruto' => number_format($margenBruto, 2),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%'
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

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Estado financiero de vehículos del negocio obtenido correctamente',
                'data' => [
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'resumen_general' => [
                        'total_ingresos' => number_format($totalGeneralIngresos, 2),
                        'total_egresos' => number_format($totalGeneralEgresos, 2),
                        'margen_bruto' => number_format($totalGeneralMargen, 2),
                        'rentabilidad_porcentaje' => number_format($rentabilidadGeneral, 2) . '%',
                        'cantidad_vehiculos' => $vehicles->count()
                    ],
                    'vehicles' => $vehiclesFinancialData
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero de vehículos del negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Obtener estado financiero de un vehículo específico en un rango de fechas
     *
     * @param Request $request
     */
    public function getVehicleFinancialStatement(Request $request)
    {
        try {
            // Validar parámetros
            $validator = Validator::make($request->all(), [
                'vehicle_id' => 'required|exists:vehicles,id',
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
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
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
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

            // Obtener transacciones por estado para el vehículo (con regla global: ingresos sin caja + todos egresos)
            $transaccionesPorEstado = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where(function ($query) {
                    // Todos los egresos
                    $query->where('tipo_de_transaccion', 'Egreso')
                        // O ingresos sin caja operativa
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
                    'financial_transactions.tipo_de_transaccion',
                    DB::raw('COUNT(*) as total_transacciones'),
                    DB::raw('SUM(importe_total) as total_importe')
                )
                ->groupBy('transaction_states.id', 'transaction_states.nombre', 'financial_transactions.tipo_de_transaccion')
                ->get();

            // Organizar datos por estado
            $estadosFinancieros = [];
            foreach ($transaccionesPorEstado as $transaccion) {
                $estadoId = $transaccion->estado_id;
                $estadoNombre = $transaccion->estado_nombre;
                $tipo = $transaccion->tipo_de_transaccion;

                if (!isset($estadosFinancieros[$estadoId])) {
                    $estadosFinancieros[$estadoId] = [
                        'estado_id' => $estadoId,
                        'estado_nombre' => $estadoNombre,
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

            // Obtener transacciones por categoría para el vehículo (con regla global: ingresos sin caja + todos egresos)
            $transaccionesPorCategoria = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where(function ($query) {
                    // Todos los egresos
                    $query->where('tipo_de_transaccion', 'Egreso')
                        // O ingresos sin caja operativa
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
                $categoriaNombre = $transaccion->categoria_nombre;
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

            // Obtener todas las transacciones del vehículo en el período (con regla global: ingresos sin caja + todos egresos)
            $transacciones = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->where(function ($query) {
                    // Todos los egresos
                    $query->where('tipo_de_transaccion', 'Egreso')
                        // O ingresos sin caja operativa
                        ->orWhere(function ($query) {
                            $query->where('tipo_de_transaccion', 'Ingreso')
                                ->whereNull('caja_operativa_id');
                        });
                })
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'estadoDeTransaccion', 'user.generalData'])
                ->orderBy('fecha', 'desc')
                ->get();

            // Verificar si el vehículo tiene un usuario asignado con datos generales
            $assignedUserName = 'Sin asignar';
            if ($vehicle->user && $vehicle->user->generalData) {
                $assignedUserName = $vehicle->user->generalData->nombre . ' ' . $vehicle->user->generalData->apellido;
            }

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Estado financiero del vehículo obtenido correctamente',
                'data' => [
                    'vehicle' => [
                        'id' => $vehicle->id,
                        'marca' => $vehicle->marca,
                        'modelo' => $vehicle->modelo,
                        'numero_placa' => $vehicle->numero_placa,
                        'anio' => $vehicle->anio,
                        'color' => $vehicle->color,
                        'usuario_asignado' => $assignedUserName
                    ],
                    'negocio' => [
                        'id' => $vehicle->negocio->id,
                        'nombre' => $vehicle->negocio->nombre
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2),
                        'total_egresos' => number_format($totalEgresos, 2),
                        'margen_bruto' => number_format($margenBruto, 2),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%',
                        'total_transacciones' => $transacciones->count()
                    ],
                    'detalle_por_estado' => array_values($estadosFinancieros),
                    'detalle_por_categoria' => array_values($categoriasFinancieras),
                    'transacciones' => $transacciones->map(function ($transaccion) {
                        // Verificar si la transacción tiene un usuario con datos generales
                        $registeredUserName = 'N/A';
                        if ($transaccion->user && $transaccion->user->generalData) {
                            $registeredUserName = $transaccion->user->generalData->nombre . ' ' . $transaccion->user->generalData->apellido;
                        }
                        return [
                            'id' => $transaccion->id,
                            'fecha' => $transaccion->fecha,
                            'item' => $transaccion->item,
                            'cantidad' => $transaccion->cantidad,
                            'importe_total' => number_format($transaccion->importe_total, 2),
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
