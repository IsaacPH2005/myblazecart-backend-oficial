<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class EgresosPorCategoriaController extends Controller
{
    /**
     * Obtener egresos por categoría filtrados por negocio y rango de fechas
     *
     * @param Request $request
     */
    public function getExpensesByCategoryByBusiness(Request $request)
    {
        try {
            // Validar parámetros
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id',
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

            // Obtener información del negocio si se especificó
            $negocio = null;
            if ($negocioId) {
                $negocio = Business::find($negocioId);
            }

            // Construir consulta base para egresos
            $query = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio']);

            // Aplicar filtro de negocio si se especificó
            if ($negocioId) {
                $query->where('negocio_id', $negocioId);
            }

            // Calcular total global de egresos
            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            // Obtener los egresos agrupados por categoría
            $egresos = $query->get()
                ->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalGlobal) {
                    $categoria = $categoriaGroup->first()->categoria;

                    // Calcular total de esta categoría
                    $totalCategoria = $categoriaGroup->sum('importe_total');

                    // Calcular porcentaje de esta categoría con respecto al total global
                    $porcentaje = $totalGlobal > 0 ? ($totalCategoria / $totalGlobal) * 100 : 0;

                    return [
                        'categoria_id' => $categoriaId,
                        'categoria_nombre' => $categoria->nombre ?? 'Sin categoría',
                        'total_categoria' => $totalCategoria, // Enviar como número puro
                        'porcentaje' => round($porcentaje, 2), // Enviar como número
                        'cantidad_transacciones' => $categoriaGroup->count(),
                        'promedio_egreso' => $categoriaGroup->avg('importe_total'), // Enviar como número
                    ];
                });

            // Ordenar categorías por total de egresos (de mayor a menor)
            $egresosOrdenados = $egresos->sortByDesc('total_categoria');

            // Obtener todas las categorías para mostrar incluso las que no tienen egresos
            $todasCategorias = \App\Models\Category::all()
                ->map(function ($categoria) use ($egresosOrdenados, $totalGlobal) {
                    $categoriaEgresos = $egresosOrdenados->firstWhere('categoria_id', $categoria->id);

                    if ($categoriaEgresos) {
                        return [
                            'categoria_id' => $categoria->id,
                            'categoria_nombre' => $categoria->nombre,
                            'total_categoria' => $categoriaEgresos['total_categoria'],
                            'porcentaje' => $categoriaEgresos['porcentaje'],
                            'cantidad_transacciones' => $categoriaEgresos['cantidad_transacciones'],
                            'promedio_egreso' => $categoriaEgresos['promedio_egreso'],
                        ];
                    } else {
                        return [
                            'categoria_id' => $categoria->id,
                            'categoria_nombre' => $categoria->nombre,
                            'total_categoria' => 0,
                            'porcentaje' => 0,
                            'cantidad_transacciones' => 0,
                            'promedio_egreso' => 0,
                        ];
                    }
                })
                // Ordenar todas las categorías por porcentaje (mayor a menor)
                ->sortByDesc('porcentaje');

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Egresos por categoría obtenidos correctamente',
                'data' => [
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'negocio' => $negocio ? [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre
                    ] : 'Global (todos los negocios)',
                    'resumen_global' => [
                        'total_egresos' => $totalGlobal, // Enviar como número
                        'cantidad_transacciones' => $cantidadGlobal,
                        'promedio_egreso' => $cantidadGlobal > 0 ? $totalGlobal / $cantidadGlobal : 0, // Enviar como número
                    ],
                    'categorias' => $todasCategorias->values()->all(),
                    'estadisticas_adicionales' => [
                        'categoria_mayor_egreso' => $egresosOrdenados->first(),
                        'categoria_menor_egreso' => $egresosOrdenados->last(),
                        'negocio_mayor_egreso' => $negocioId ? null : $this->getNegocioMayorEgreso($fechaInicial, $fechaFinal),
                        'distribucion_porcentual' => $this->getDistribucionPorcentual($egresosOrdenados, $totalGlobal)
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener egresos por categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener el negocio con mayor egreso en el período
     */
    private function getNegocioMayorEgreso($fechaInicial, $fechaFinal)
    {
        $negocioMayor = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
            ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
            ->join('businesses', 'financial_transactions.negocio_id', '=', 'businesses.id')
            ->select(
                'businesses.id',
                'businesses.nombre',
                DB::raw('SUM(financial_transactions.importe_total) as total_egresos')
            )
            ->groupBy('businesses.id', 'businesses.nombre')
            ->orderBy('total_egresos', 'desc')
            ->first();

        return $negocioMayor ? [
            'id' => $negocioMayor->id,
            'nombre' => $negocioMayor->nombre,
            'total_egresos' => $negocioMayor->total_egresos // Enviar como número
        ] : null;
    }

    /**
     * Obtener distribución porcentual de egresos por categoría
     */
    private function getDistribucionPorcentual($egresos, $totalGlobal)
    {
        if ($totalGlobal <= 0) {
            return [];
        }

        return $egresos->map(function ($categoria) use ($totalGlobal) {
            return [
                'categoria_id' => $categoria['categoria_id'],
                'categoria_nombre' => $categoria['categoria_nombre'],
                'total_categoria' => $categoria['total_categoria'],
                'porcentaje' => round(($categoria['total_categoria'] / $totalGlobal) * 100, 2) // Redondear a 2 decimales
            ];
        })->sortByDesc('porcentaje')->values()->all();
    }
}
