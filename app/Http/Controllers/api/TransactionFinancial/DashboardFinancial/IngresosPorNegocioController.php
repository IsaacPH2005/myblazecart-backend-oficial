<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Exports\IncomesByBusinessExport;
use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Facades\Excel;

class IngresosPorNegocioController extends Controller
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

    public function getIncomesByBusiness(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id',
                'vehicle_id' => 'nullable|exists:vehicles,id',
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
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            $negocio = $negocioId ? Business::find($negocioId) : null;
            $vehiculo = $vehicleId ? Vehicle::with('negocio')->find($vehicleId) : null;

            $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            if ($negocioId) {
                $query->where('negocio_id', $negocioId);
            }

            if ($vehicleId) {
                $query->where('vehicle_id', $vehicleId);
            }

            $ingresos = $query->get()
                ->groupBy('negocio_id')
                ->map(function ($negocioGroup, $negocioId) {
                    $negocio = $negocioGroup->first()->negocio;
                    $negocioNombre = $negocio ? $negocio->nombre : 'Sin negocio';

                    $categorias = $negocioGroup->groupBy('categoria_id')
                        ->map(function ($categoriaGroup) {
                            $categoria = $categoriaGroup->first()->categoria;
                            $categoriaNombre = $categoria ? $categoria->nombre : 'Sin categoría';
                            $categoriaId = $categoria ? $categoria->id : null;

                            return [
                                'categoria_id' => $categoriaId,
                                'categoria_nombre' => $categoriaNombre,
                                'total_ingresos' => $categoriaGroup->sum('importe_total'),
                                'cantidad_transacciones' => $categoriaGroup->count(),
                                'promedio_ingreso' => $categoriaGroup->count() > 0
                                    ? $categoriaGroup->avg('importe_total')
                                    : 0,
                            ];
                        });

                    return [
                        'negocio_id' => $negocioId,
                        'negocio_nombre' => $negocioNombre,
                        'total_ingresos' => $negocioGroup->sum('importe_total'),
                        'cantidad_transacciones' => $negocioGroup->count(),
                        'promedio_ingreso' => $negocioGroup->count() > 0
                            ? $negocioGroup->avg('importe_total')
                            : 0,
                        'categorias' => $categorias->values()->all()
                    ];
                });

            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            $todosNegocios = Business::all()
                ->map(function ($negocio) use ($ingresos) {
                    $negocioIngresos = $ingresos->firstWhere('negocio_id', $negocio->id);

                    return [
                        'negocio_id' => $negocio->id,
                        'negocio_nombre' => $negocio->nombre,
                        'total_ingresos' => $negocioIngresos ? $negocioIngresos['total_ingresos'] : 0,
                        'cantidad_transacciones' => $negocioIngresos ? $negocioIngresos['cantidad_transacciones'] : 0,
                        'promedio_ingreso' => $negocioIngresos ? $negocioIngresos['promedio_ingreso'] : 0,
                        'categorias' => $negocioIngresos ? $negocioIngresos['categorias'] : []
                    ];
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
                ];
            }

            $response = [
                'status' => 'success',
                'message' => 'Ingresos por negocio obtenidos correctamente',
                'data' => [
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
                    ],
                    'negocio' => $negocio ? [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre
                    ] : null,
                    'vehiculo' => $vehiculoInfo,
                    'resumen_global' => [
                        'total_ingresos' => $totalGlobal,
                        'cantidad_transacciones' => $cantidadGlobal,
                        'promedio_ingreso' => $cantidadGlobal > 0 ? $totalGlobal / $cantidadGlobal : 0
                    ],
                    'negocios' => $todosNegocios,
                    'estadisticas_adicionales' => [
                        'negocio_mayor_ingreso' => $ingresos->sortByDesc('total_ingresos')->first(),
                        'negocio_menor_ingreso' => $ingresos->sortBy('total_ingresos')->first(),
                        'distribucion_porcentual' => $this->getDistribucionPorcentualIngresos($ingresos, $totalGlobal)
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener ingresos por negocio',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function getDistribucionPorcentualIngresos($ingresos, $totalGlobal)
    {
        if ($totalGlobal <= 0) {
            return [];
        }

        return $ingresos->map(function ($negocio) use ($totalGlobal) {
            return [
                'negocio_id' => $negocio['negocio_id'],
                'negocio_nombre' => $negocio['negocio_nombre'],
                'total_ingresos' => $negocio['total_ingresos'],
                'porcentaje' => round(($negocio['total_ingresos'] / $totalGlobal) * 100, 2)
            ];
        })->sortByDesc('porcentaje')->values()->all();
    }

    private function getProcessedIncomesByBusiness(Request $request)
    {
        $negocioId = $request->input('negocio_id');
        $vehicleId = $request->input('vehicle_id');
        $fechaInicial = $request->input('fecha_inicial');
        $fechaFinal = $request->input('fecha_final');

        $negocio = $negocioId ? Business::find($negocioId) : null;
        $vehiculo = $vehicleId ? Vehicle::with('negocio')->find($vehicleId) : null;

        $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
            ->whereNull('caja_operativa_id')
            ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
            ->with(['categoria', 'negocio', 'vehicle']);

        if ($negocioId) {
            $query->where('negocio_id', $negocioId);
        }

        if ($vehicleId) {
            $query->where('vehicle_id', $vehicleId);
        }

        $ingresos = $query->get()
            ->groupBy('negocio_id')
            ->map(function ($negocioGroup, $negocioId) {
                $negocio = $negocioGroup->first()->negocio;
                $negocioNombre = $negocio ? $negocio->nombre : 'Sin negocio';

                $categorias = $negocioGroup->groupBy('categoria_id')
                    ->map(function ($categoriaGroup) {
                        $categoria = $categoriaGroup->first()->categoria;
                        $categoriaNombre = $categoria ? $categoria->nombre : 'Sin categoría';
                        $categoriaId = $categoria ? $categoria->id : null;

                        return [
                            'categoria_id' => $categoriaId,
                            'categoria_nombre' => $categoriaNombre,
                            'total_ingresos' => $categoriaGroup->sum('importe_total'),
                            'cantidad_transacciones' => $categoriaGroup->count(),
                            'promedio_ingreso' => $categoriaGroup->count() > 0
                                ? $categoriaGroup->avg('importe_total')
                                : 0,
                        ];
                    });

                return [
                    'negocio_id' => $negocioId,
                    'negocio_nombre' => $negocioNombre,
                    'total_ingresos' => $negocioGroup->sum('importe_total'),
                    'cantidad_transacciones' => $negocioGroup->count(),
                    'promedio_ingreso' => $negocioGroup->count() > 0
                        ? $negocioGroup->avg('importe_total')
                        : 0,
                    'categorias' => $categorias->values()->all()
                ];
            });

        $totalGlobal = $query->sum('importe_total');
        $cantidadGlobal = $query->count();

        $todosNegocios = Business::all()
            ->map(function ($negocio) use ($ingresos) {
                $negocioIngresos = $ingresos->firstWhere('negocio_id', $negocio->id);

                return [
                    'negocio_id' => $negocio->id,
                    'negocio_nombre' => $negocio->nombre,
                    'total_ingresos' => $negocioIngresos ? $negocioIngresos['total_ingresos'] : 0,
                    'cantidad_transacciones' => $negocioIngresos ? $negocioIngresos['cantidad_transacciones'] : 0,
                    'promedio_ingreso' => $negocioIngresos ? $negocioIngresos['promedio_ingreso'] : 0,
                    'categorias' => $negocioIngresos ? $negocioIngresos['categorias'] : []
                ];
            });

        return [
            'periodo' => [
                'fecha_inicial' => $fechaInicial,
                'fecha_final' => $fechaFinal,
                'dias' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1
            ],
            'negocio' => $negocio,
            'vehiculo' => $vehiculo,
            'resumen_global' => [
                'total_ingresos' => floatval($totalGlobal),
                'cantidad_transacciones' => intval($cantidadGlobal),
                'promedio_ingreso' => $cantidadGlobal > 0 ? floatval($totalGlobal / $cantidadGlobal) : 0
            ],
            'negocios' => $todosNegocios,
            'ingresos' => $ingresos,
            'distribucion_porcentual' => $this->getDistribucionPorcentualIngresos($ingresos, $totalGlobal)
        ];
    }

    public function exportIncomesByBusinessToExcel(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'nullable|exists:businesses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $export = new IncomesByBusinessExport($request);

            $negocioNombre = 'Todos_Negocios';
            if ($request->negocio_id) {
                $negocio = Business::find($request->negocio_id);
                if ($negocio) {
                    $negocioNombre = str_replace(' ', '_', $negocio->nombre);
                }
            }

            $vehiculoInfo = '';
            if ($request->vehicle_id) {
                $vehiculo = Vehicle::find($request->vehicle_id);
                if ($vehiculo) {
                    $vehiculoInfo = '_Vehiculo_' . str_replace(' ', '_', $vehiculo->numero_placa);
                }
            }

            $nombreArchivo = 'Ingresos_por_Negocio_' . $negocioNombre . $vehiculoInfo . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.xlsx';

            return Excel::download($export, $nombreArchivo);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function exportIncomesByBusinessToPDF(Request $request)
    {
        if (!Auth::check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario no autenticado',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'negocio_id' => 'nullable|exists:businesses,id',
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'fecha_inicial' => 'required|date',
            'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $data = $this->getProcessedIncomesByBusiness($request);

            $data['negocios'] = $data['negocios']->map(function ($negocio) {
                return [
                    'negocio_id' => $negocio['negocio_id'],
                    'negocio_nombre' => $negocio['negocio_nombre'],
                    'total_ingresos' => floatval($negocio['total_ingresos']),
                    'cantidad_transacciones' => intval($negocio['cantidad_transacciones']),
                    'promedio_ingreso' => floatval($negocio['promedio_ingreso']),
                    'categorias' => collect($negocio['categorias'])->map(function ($categoria) {
                        return [
                            'categoria_id' => $categoria['categoria_id'],
                            'categoria_nombre' => $categoria['categoria_nombre'],
                            'total_ingresos' => floatval($categoria['total_ingresos']),
                            'cantidad_transacciones' => intval($categoria['cantidad_transacciones']),
                            'promedio_ingreso' => floatval($categoria['promedio_ingreso']),
                        ];
                    })->all()
                ];
            })->all();

            $data['estadisticas_adicionales'] = [
                'negocio_mayor_ingreso' => $data['ingresos']->sortByDesc('total_ingresos')->first(),
                'negocio_menor_ingreso' => $data['ingresos']->sortBy('total_ingresos')->first(),
                'distribucion_porcentual' => collect($data['distribucion_porcentual'])->map(function ($item) {
                    return [
                        'negocio_id' => $item['negocio_id'],
                        'negocio_nombre' => $item['negocio_nombre'],
                        'total_ingresos' => floatval($item['total_ingresos']),
                        'porcentaje' => floatval($item['porcentaje'])
                    ];
                })->all()
            ];

            if ($data['vehiculo']) {
                $data['vehiculo_info'] = [
                    'id' => $data['vehiculo']->id,
                    'placa' => $data['vehiculo']->numero_placa,
                    'marca' => $data['vehiculo']->marca,
                    'modelo' => $data['vehiculo']->modelo,
                    'negocio' => $data['vehiculo']->negocio ? [
                        'id' => $data['vehiculo']->negocio->id,
                        'nombre' => $data['vehiculo']->negocio->nombre
                    ] : null
                ];
            }

            $pdf = Pdf::loadView('exports.incomes_by_business_pdf', $data)
                ->setPaper('a4', 'portrait');

            $negocioNombre = 'Todos_Negocios';
            if ($request->negocio_id) {
                $negocio = Business::find($request->negocio_id);
                if ($negocio) {
                    $negocioNombre = str_replace(' ', '_', $negocio->nombre);
                }
            }

            $vehiculoInfo = '';
            if ($request->vehicle_id) {
                $vehiculo = Vehicle::find($request->vehicle_id);
                if ($vehiculo) {
                    $vehiculoInfo = '_Vehiculo_' . str_replace(' ', '_', $vehiculo->numero_placa);
                }
            }

            $nombreArchivo = 'Ingresos_por_Negocio_' . $negocioNombre . $vehiculoInfo . '_' .
                $request->fecha_inicial . '_a_' .
                $request->fecha_final . '_' .
                date('Y-m-d_H-i-s') . '.pdf';

            return $pdf->download($nombreArchivo);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a PDF',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
