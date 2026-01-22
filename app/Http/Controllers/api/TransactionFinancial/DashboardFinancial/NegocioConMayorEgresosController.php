<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class NegocioConMayorEgresosController extends Controller
{
    public function getVehiclesByBusiness(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Parámetros inválidos',
                    'errors' => $validator->errors()
                ], 422);
            }

            $vehiculos = Vehicle::where('negocio_id', $request->negocio_id)
                ->where('estado', 'ACTIVO')
                ->orderBy('numero_placa')
                ->get()
                ->map(function ($vehiculo) {
                    return [
                        'id' => $vehiculo->id,
                        'codigo_unico' => $vehiculo->codigo_unico ?? $vehiculo->id,
                        'placa' => $vehiculo->numero_placa,
                        'marca' => $vehiculo->marca,
                        'modelo' => $vehiculo->modelo,
                        'año' => $vehiculo->año,
                        'tipo_vehiculo' => $vehiculo->tipo_vehiculo,
                        'nombre_display' => $vehiculo->numero_placa . ' - ' . $vehiculo->marca . ' ' . $vehiculo->modelo,
                    ];
                });

            return response()->json([
                'status' => 'success',
                'datos' => $vehiculos,
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener vehículos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBusinessWithHighestExpense(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'fecha_inicial' => 'nullable|date',
                'fecha_final' => 'nullable|date|after_or_equal:fecha_inicial',
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

            $negocio = $negocioId ? Business::find($negocioId) : null;
            $vehiculo = $vehicleId ? Vehicle::with('negocio')->find($vehicleId) : null;

            $query = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereNull('caja_operativa_id')
                ->join('businesses', 'financial_transactions.negocio_id', '=', 'businesses.id');

            if ($negocioId) {
                $query->where('financial_transactions.negocio_id', $negocioId);
            }

            if ($vehicleId) {
                $query->where('financial_transactions.vehicle_id', $vehicleId);
            }

            if ($fechaInicial && $fechaFinal) {
                $query->whereBetween('financial_transactions.fecha', [$fechaInicial, $fechaFinal]);
            }

            $negocioMayor = $query->select(
                'businesses.id',
                'businesses.nombre',
                DB::raw('SUM(financial_transactions.importe_total) as total_egresos'),
                DB::raw('COUNT(financial_transactions.id) as cantidad_transacciones'),
                DB::raw('AVG(financial_transactions.importe_total) as promedio_egreso')
            )
                ->groupBy('businesses.id', 'businesses.nombre')
                ->orderBy('total_egresos', 'desc')
                ->first();

            if (!$negocioMayor) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No se encontraron egresos registrados',
                    'data' => [
                        'periodo' => $this->getPeriodoInfo($fechaInicial, $fechaFinal),
                        'filtros_aplicados' => $this->getFiltrosAplicados($negocio, $vehiculo),
                        'negocio' => null,
                        'desglose_categorias' => [],
                        'comparacion_negocios' => []
                    ]
                ], 200);
            }

            $queryCategoria = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereNull('caja_operativa_id')
                ->where('financial_transactions.negocio_id', $negocioMayor->id)
                ->join('categories', 'financial_transactions.categoria_id', '=', 'categories.id');

            if ($vehicleId) {
                $queryCategoria->where('financial_transactions.vehicle_id', $vehicleId);
            }

            if ($fechaInicial && $fechaFinal) {
                $queryCategoria->whereBetween('financial_transactions.fecha', [$fechaInicial, $fechaFinal]);
            }

            $desgloseCategorias = $queryCategoria->select(
                'categories.id',
                'categories.nombre',
                DB::raw('SUM(financial_transactions.importe_total) as total_categoria'),
                DB::raw('COUNT(financial_transactions.id) as cantidad_transacciones'),
                DB::raw('AVG(financial_transactions.importe_total) as promedio_categoria')
            )
                ->groupBy('categories.id', 'categories.nombre')
                ->orderBy('total_categoria', 'desc')
                ->get();

            $totalEgresosNegocio = floatval($negocioMayor->total_egresos);

            $queryComparacion = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereNull('caja_operativa_id')
                ->join('businesses', 'financial_transactions.negocio_id', '=', 'businesses.id');

            if ($vehicleId) {
                $queryComparacion->where('financial_transactions.vehicle_id', $vehicleId);
            }

            if ($fechaInicial && $fechaFinal) {
                $queryComparacion->whereBetween('financial_transactions.fecha', [$fechaInicial, $fechaFinal]);
            }

            $comparacionNegocios = $queryComparacion->select(
                'businesses.id',
                'businesses.nombre',
                DB::raw('SUM(financial_transactions.importe_total) as total_egresos'),
                DB::raw('COUNT(financial_transactions.id) as cantidad_transacciones'),
                DB::raw('AVG(financial_transactions.importe_total) as promedio_egreso')
            )
                ->groupBy('businesses.id', 'businesses.nombre')
                ->orderBy('total_egresos', 'desc')
                ->limit(5)
                ->get();

            $totalGlobal = $comparacionNegocios->sum(function ($item) {
                return floatval($item->total_egresos);
            });

            $vehiculoInfo = null;
            if ($vehiculo) {
                $vehiculoInfo = [
                    'id' => $vehiculo->id,
                    'codigo_unico' => $vehiculo->codigo_unico ?? $vehiculo->id,
                    'numero_placa' => $vehiculo->numero_placa,
                    'marca' => $vehiculo->marca,
                    'modelo' => $vehiculo->modelo,
                    'año' => $vehiculo->año,
                    'tipo_vehiculo' => $vehiculo->tipo_vehiculo,
                    'nombre_display' => $vehiculo->numero_placa . ' - ' . $vehiculo->marca . ' ' . $vehiculo->modelo,
                    'negocio' => $vehiculo->negocio ? [
                        'id' => $vehiculo->negocio->id,
                        'nombre' => $vehiculo->negocio->nombre
                    ] : null
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Negocio con mayor egreso obtenido correctamente',
                'data' => [
                    'periodo' => $this->getPeriodoInfo($fechaInicial, $fechaFinal),
                    'filtros_aplicados' => $this->getFiltrosAplicados($negocio, $vehiculo),
                    'negocio' => [
                        'id' => $negocioMayor->id,
                        'nombre' => $negocioMayor->nombre,
                        'total_egresos' => floatval($negocioMayor->total_egresos),
                        'cantidad_transacciones' => intval($negocioMayor->cantidad_transacciones),
                        'promedio_egreso' => floatval($negocioMayor->promedio_egreso),
                        'porcentaje_del_total' => $totalGlobal > 0
                            ? round((floatval($negocioMayor->total_egresos) / $totalGlobal) * 100, 2)
                            : 0
                    ],
                    'vehiculo' => $vehiculoInfo,
                    'desglose_categorias' => $desgloseCategorias->map(function ($categoria) use ($totalEgresosNegocio) {
                        $totalCategoria = floatval($categoria->total_categoria);
                        return [
                            'id' => $categoria->id,
                            'nombre' => $categoria->nombre,
                            'total_categoria' => $totalCategoria,
                            'cantidad_transacciones' => intval($categoria->cantidad_transacciones),
                            'promedio_categoria' => floatval($categoria->promedio_categoria),
                            'porcentaje' => $totalEgresosNegocio > 0
                                ? round(($totalCategoria / $totalEgresosNegocio) * 100, 2)
                                : 0
                        ];
                    }),
                    'comparacion_negocios' => $comparacionNegocios->map(function ($negocio) use ($totalGlobal) {
                        $totalNegocio = floatval($negocio->total_egresos);
                        return [
                            'id' => $negocio->id,
                            'nombre' => $negocio->nombre,
                            'total_egresos' => $totalNegocio,
                            'cantidad_transacciones' => intval($negocio->cantidad_transacciones),
                            'promedio_egreso' => floatval($negocio->promedio_egreso),
                            'porcentaje_del_total' => $totalGlobal > 0
                                ? round(($totalNegocio / $totalGlobal) * 100, 2)
                                : 0
                        ];
                    }),
                    'resumen_global' => [
                        'total_egresos_periodo' => $totalGlobal,
                        'total_negocios' => $comparacionNegocios->count(),
                        'promedio_por_negocio' => $comparacionNegocios->count() > 0
                            ? $totalGlobal / $comparacionNegocios->count()
                            : 0
                    ],
                    'estadisticas_adicionales' => [
                        'categoria_mayor_gasto' => $desgloseCategorias->first(),
                        'categoria_menor_gasto' => $desgloseCategorias->last(),
                        'total_categorias' => $desgloseCategorias->count()
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el negocio con mayor egreso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getPeriodoInfo($fechaInicial, $fechaFinal)
    {
        if (!$fechaInicial || !$fechaFinal) {
            return [
                'tipo' => 'historico',
                'descripcion' => 'Todos los tiempos',
                'fecha_inicial' => null,
                'fecha_final' => null,
                'dias' => null
            ];
        }

        return [
            'tipo' => 'rango',
            'descripcion' => 'Período personalizado',
            'fecha_inicial' => $fechaInicial,
            'fecha_final' => $fechaFinal,
            'dias' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
        ];
    }

    private function getFiltrosAplicados($negocio, $vehiculo)
    {
        return [
            'negocio' => $negocio ? [
                'id' => $negocio->id,
                'nombre' => $negocio->nombre
            ] : null,
            'vehiculo' => $vehiculo ? [
                'id' => $vehiculo->id,
                'numero_placa' => $vehiculo->numero_placa,
                'marca' => $vehiculo->marca,
                'modelo' => $vehiculo->modelo,
                'nombre_display' => $vehiculo->numero_placa . ' - ' . $vehiculo->marca . ' ' . $vehiculo->modelo,
                'negocio' => $vehiculo->negocio ? [
                    'id' => $vehiculo->negocio->id,
                    'nombre' => $vehiculo->negocio->nombre
                ] : null
            ] : null
        ];
    }
}
