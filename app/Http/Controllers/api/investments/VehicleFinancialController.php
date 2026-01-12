<?php

namespace App\Http\Controllers\api\investments;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use App\Models\Investment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Validator;

class VehicleFinancialController extends Controller
{
    /**
     * Obtener estado financiero de todos los vehículos de un negocio
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehiclesFinancialStatementByBusiness(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'negocio_id' => 'required|exists:businesses,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $negocioId = $request->negocio_id;
            $fechaInicial = $request->fecha_inicial;
            $fechaFinal = $request->fecha_final;

            // Verificar que el negocio existe
            $negocio = Business::find($negocioId);
            if (!$negocio) {
                return response()->json([
                    'success' => false,
                    'message' => 'Negocio no encontrado'
                ], 404);
            }

            // Obtener todos los vehículos del negocio con inversiones activas
            $vehiculos = Vehicle::whereHas('investments', function ($query) use ($negocioId) {
                $query->where('business_id', $negocioId)
                    ->whereIn('estado', ['activo', 'pendiente']);
            })
                ->with(['investments' => function ($query) use ($negocioId) {
                    $query->where('business_id', $negocioId);
                }])
                ->get();

            if ($vehiculos->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No se encontraron vehículos para este negocio',
                    'data' => [
                        'negocio' => [
                            'id' => $negocio->id,
                            'nombre' => $negocio->nombre,
                        ],
                        'periodo' => [
                            'fecha_inicial' => $fechaInicial,
                            'fecha_final' => $fechaFinal,
                            'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                        ],
                        'resumen_general' => [
                            'total_ingresos' => '0.00',
                            'total_egresos' => '0.00',
                            'margen_bruto' => '0.00',
                            'rentabilidad_porcentaje' => '0.00%',
                        ],
                        'vehicles' => []
                    ]
                ], 200);
            }

            $vehiclesData = [];
            $totalIngresosGeneral = 0;
            $totalEgresosGeneral = 0;

            foreach ($vehiculos as $vehiculo) {
                // Obtener transacciones del vehículo en el período
                $transacciones = FinancialTransactions::where('vehicle_id', $vehiculo->id)
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->get();

                $ingresos = $transacciones->where('tipo_de_transaccion', 'Ingreso')->sum('importe_total');
                $egresos = $transacciones->where('tipo_de_transaccion', 'Egreso')->sum('importe_total');
                $margenBruto = $ingresos - $egresos;
                $rentabilidad = $egresos > 0 ? (($margenBruto / $egresos) * 100) : 0;

                $totalIngresosGeneral += $ingresos;
                $totalEgresosGeneral += $egresos;

                $vehiclesData[] = [
                    'vehicle_id' => $vehiculo->id,
                    'vehicle_info' => [
                        'numero_placa' => $vehiculo->numero_placa,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'año' => $vehiculo->año,
                        'tipo' => $vehiculo->tipo,
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($ingresos, 2, '.', ','),
                        'total_egresos' => number_format($egresos, 2, '.', ','),
                        'margen_bruto' => number_format($margenBruto, 2, '.', ','),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%',
                    ],
                    'total_transacciones' => $transacciones->count(),
                ];
            }

            $margenBrutoGeneral = $totalIngresosGeneral - $totalEgresosGeneral;
            $rentabilidadGeneral = $totalEgresosGeneral > 0
                ? (($margenBrutoGeneral / $totalEgresosGeneral) * 100)
                : 0;

            return response()->json([
                'success' => true,
                'message' => 'Estado financiero de vehículos obtenido exitosamente',
                'data' => [
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                        'descripcion' => $negocio->descripcion,
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'resumen_general' => [
                        'total_ingresos' => number_format($totalIngresosGeneral, 2, '.', ','),
                        'total_egresos' => number_format($totalEgresosGeneral, 2, '.', ','),
                        'margen_bruto' => number_format($margenBrutoGeneral, 2, '.', ','),
                        'rentabilidad_porcentaje' => number_format($rentabilidadGeneral, 2) . '%',
                    ],
                    'vehicles' => $vehiclesData,
                    'total_vehicles' => count($vehiclesData),
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el estado financiero de vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener estado financiero detallado de un vehículo específico
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehicleFinancialStatement(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'vehicle_id' => 'required|exists:vehicles,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $vehicleId = $request->vehicle_id;
            $fechaInicial = $request->fecha_inicial;
            $fechaFinal = $request->fecha_final;

            // Obtener el vehículo con sus relaciones
            $vehiculo = Vehicle::with(['investments.business'])->find($vehicleId);

            if (!$vehiculo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehículo no encontrado'
                ], 404);
            }

            // Obtener todas las transacciones del vehículo en el período
            $transacciones = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'estadoDeTransaccion']) // CAMBIADO de 'state' a 'estadoDeTransaccion'
                ->orderBy('fecha', 'desc')
                ->get();

            // Calcular resumen financiero general
            $totalIngresos = $transacciones->where('tipo_de_transaccion', 'Ingreso')->sum('importe_total');
            $totalEgresos = $transacciones->where('tipo_de_transaccion', 'Egreso')->sum('importe_total');
            $margenBruto = $totalIngresos - $totalEgresos;
            $rentabilidad = $totalEgresos > 0 ? (($margenBruto / $totalEgresos) * 100) : 0;

            // Detalle por estado
            $detallePorEstado = $transacciones->groupBy('estado_de_transaccion_id')->map(function ($transaccionesEstado) {
                $ingresos = $transaccionesEstado->where('tipo_de_transaccion', 'Ingreso')->sum('importe_total');
                $egresos = $transaccionesEstado->where('tipo_de_transaccion', 'Egreso')->sum('importe_total');

                return [
                    'estado_id' => $transaccionesEstado->first()->estado_de_transaccion_id,
                    'estado_nombre' => $transaccionesEstado->first()->estadoDeTransaccion->nombre ?? 'Sin estado',
                    'ingresos' => $ingresos,
                    'egresos' => $egresos,
                    'balance_estado' => $ingresos - $egresos,
                    'total_transacciones_ingresos' => $transaccionesEstado->where('tipo_de_transaccion', 'Ingreso')->count(),
                    'total_transacciones_egresos' => $transaccionesEstado->where('tipo_de_transaccion', 'Egreso')->count(),
                ];
            })->values();

            // Detalle por categoría
            $detallePorCategoria = $transacciones->groupBy('categoria_id')->map(function ($transaccionesCategoria) {
                $ingresos = $transaccionesCategoria->where('tipo_de_transaccion', 'Ingreso')->sum('importe_total');
                $egresos = $transaccionesCategoria->where('tipo_de_transaccion', 'Egreso')->sum('importe_total');

                return [
                    'categoria_id' => $transaccionesCategoria->first()->categoria_id,
                    'categoria_nombre' => $transaccionesCategoria->first()->categoria->nombre ?? 'Sin categoría',
                    'ingresos' => $ingresos,
                    'egresos' => $egresos,
                    'balance_categoria' => $ingresos - $egresos,
                    'total_transacciones_ingresos' => $transaccionesCategoria->where('tipo_de_transaccion', 'Ingreso')->count(),
                    'total_transacciones_egresos' => $transaccionesCategoria->where('tipo_de_transaccion', 'Egreso')->count(),
                ];
            })->values();

            // Formatear transacciones para la respuesta
            $transaccionesFormateadas = $transacciones->map(function ($transaccion) {
                return [
                    'id' => $transaccion->id,
                    'fecha' => $transaccion->fecha,
                    'item' => $transaccion->item,
                    'descripcion' => $transaccion->descripcion,
                    'importe_total' => number_format($transaccion->importe_total, 2, '.', ','),
                    'tipo_de_transaccion' => $transaccion->tipo_de_transaccion,
                    'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                    'estado' => $transaccion->estadoDeTransaccion->nombre ?? 'Sin estado',
                ];
            });

            // Obtener inversiones relacionadas al vehículo
            $inversiones = $vehiculo->investments->map(function ($investment) {
                return [
                    'id' => $investment->id,
                    'monto_inversion' => number_format($investment->monto_inversion, 2, '.', ','),
                    'porcentaje_participacion' => number_format($investment->porcentaje_participacion, 2),
                    'estado' => $investment->estado,
                    'negocio' => $investment->business ? [
                        'id' => $investment->business->id,
                        'nombre' => $investment->business->nombre,
                    ] : null,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Estado financiero del vehículo obtenido exitosamente',
                'data' => [
                    'vehicle' => [
                        'id' => $vehiculo->id,
                        'numero_placa' => $vehiculo->numero_placa,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'año' => $vehiculo->año,
                        'tipo' => $vehiculo->tipo,
                        'color' => $vehiculo->color,
                        'kilometraje' => $vehiculo->kilometraje,
                    ],
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2, '.', ','),
                        'total_egresos' => number_format($totalEgresos, 2, '.', ','),
                        'margen_bruto' => number_format($margenBruto, 2, '.', ','),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%',
                    ],
                    'detalle_por_estado' => $detallePorEstado,
                    'detalle_por_categoria' => $detallePorCategoria,
                    'transacciones' => $transaccionesFormateadas,
                    'total_transacciones' => $transacciones->count(),
                    'inversiones' => $inversiones,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el estado financiero del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener transacciones de un vehículo con paginación
     *
     * @param Request $request
     * @param int $vehicleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehicleTransactions(Request $request, $vehicleId)
    {
        $validator = Validator::make(array_merge($request->all(), ['vehicle_id' => $vehicleId]), [
            'vehicle_id' => 'required|exists:vehicles,id',
            'fecha_inicial' => 'nullable|date',
            'fecha_final' => 'nullable|date|after_or_equal:fecha_inicial',
            'tipo' => 'nullable|in:Ingreso,Egreso',
            'estado_id' => 'nullable|exists:transaction_states,id',
            'categoria_id' => 'nullable|exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos de entrada inválidos',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $vehiculo = Vehicle::find($vehicleId);

            if (!$vehiculo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vehículo no encontrado'
                ], 404);
            }

            $query = FinancialTransactions::where('vehicle_id', $vehicleId)
                ->with(['categoria', 'estadoDeTransaccion']);

            // Aplicar filtros opcionales
            if ($request->filled('fecha_inicial') && $request->filled('fecha_final')) {
                $query->whereBetween('fecha', [$request->fecha_inicial, $request->fecha_final]);
            }

            if ($request->filled('tipo')) {
                $query->where('tipo_de_transaccion', $request->tipo);
            }

            if ($request->filled('estado_id')) {
                $query->where('estado_de_transaccion_id', $request->estado_id);
            }

            if ($request->filled('categoria_id')) {
                $query->where('categoria_id', $request->categoria_id);
            }

            // Ordenar por fecha descendente
            $query->orderBy('fecha', 'desc');

            // Paginar resultados
            $perPage = $request->input('per_page', 15);
            $transacciones = $query->paginate($perPage);

            $transaccionesFormateadas = $transacciones->map(function ($transaccion) {
                return [
                    'id' => $transaccion->id,
                    'fecha' => $transaccion->fecha,
                    'item' => $transaccion->item,
                    'descripcion' => $transaccion->descripcion,
                    'importe_total' => number_format($transaccion->importe_total, 2, '.', ','),
                    'tipo_de_transaccion' => $transaccion->tipo_de_transaccion,
                    'categoria' => [
                        'id' => $transaccion->categoria->id ?? null,
                        'nombre' => $transaccion->categoria->nombre ?? 'Sin categoría',
                    ],
                    'estado' => [
                        'id' => $transaccion->estadoDeTransaccion->id ?? null,
                        'nombre' => $transaccion->estadoDeTransaccion->nombre ?? 'Sin estado',
                    ],
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Transacciones del vehículo obtenidas exitosamente',
                'data' => $transaccionesFormateadas,
                'pagination' => [
                    'total' => $transacciones->total(),
                    'per_page' => $transacciones->perPage(),
                    'current_page' => $transacciones->currentPage(),
                    'last_page' => $transacciones->lastPage(),
                    'from' => $transacciones->firstItem(),
                    'to' => $transacciones->lastItem(),
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las transacciones del vehículo',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen de rendimiento de todos los vehículos con inversiones
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getVehiclesPerformanceSummary(Request $request)
    {
        try {
            $fechaInicial = $request->input('fecha_inicial', now()->startOfMonth()->toDateString());
            $fechaFinal = $request->input('fecha_final', now()->toDateString());

            $vehiculos = Vehicle::whereHas('investments', function ($query) {
                $query->whereIn('estado', ['activo', 'pendiente']);
            })
                ->with(['investments' => function ($query) {
                    $query->whereIn('estado', ['activo', 'pendiente']);
                }])
                ->get();

            $resumen = $vehiculos->map(function ($vehiculo) use ($fechaInicial, $fechaFinal) {
                $transacciones = FinancialTransactions::where('vehicle_id', $vehiculo->id)
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->get();

                $ingresos = $transacciones->where('tipo_de_transaccion', 'Ingreso')->sum('importe_total');
                $egresos = $transacciones->where('tipo_de_transaccion', 'Egreso')->sum('importe_total');
                $margenBruto = $ingresos - $egresos;
                $rentabilidad = $egresos > 0 ? (($margenBruto / $egresos) * 100) : 0;

                return [
                    'vehicle_id' => $vehiculo->id,
                    'numero_placa' => $vehiculo->numero_placa,
                    'marca' => $vehiculo->marca,
                    'modelo' => $vehiculo->modelo,
                    'ingresos' => number_format($ingresos, 2, '.', ','),
                    'egresos' => number_format($egresos, 2, '.', ','),
                    'margen_bruto' => number_format($margenBruto, 2, '.', ','),
                    'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%',
                    'total_inversiones' => $vehiculo->investments->sum('monto_inversion'),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Resumen de rendimiento de vehículos obtenido exitosamente',
                'data' => $resumen,
                'periodo' => [
                    'fecha_inicial' => $fechaInicial,
                    'fecha_final' => $fechaFinal,
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen de rendimiento',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
