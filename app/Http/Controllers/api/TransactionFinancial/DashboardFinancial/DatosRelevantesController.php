<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DatosRelevantesController extends Controller
{
    /**
     * Obtener datos relevantes de la operación
     * Similar a la tabla Excel mostrada
     */
    public function getOperationReport(Request $request)
    {
        try {
            // Validar fechas de entrada
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'negocio_id' => 'nullable|exists:businesses,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            // Query base con filtros opcionales
            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true); // Solo transacciones activas

            // Filtros opcionales
            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            if ($request->negocio_id) {
                $query->where('negocio_id', $request->negocio_id);
            }

            // Calcular días transcurridos
            $diasTranscurridos = $fechaInicial->diffInDays($fechaFinal) + 1;

            // Calcular productividad total (suma de ingresos, EXCLUIR ingresos de caja operativa)
            $productividad = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                ->sum('importe_total');

            // Calcular millas recorridas en servicio
            $millasRecorridas = $query->clone()
                ->whereNotNull('millas')
                ->sum('millas');

            // Calcular productividad por día
            $productividadPorDia = $diasTranscurridos > 0 ?
                round($productividad / $diasTranscurridos, 2) : 0;

            // Calcular carga mejor pagada (transacción con mayor importe, EXCLUIR ingresos de caja operativa)
            $cargaMejorPagada = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                ->orderBy('importe_total', 'desc')
                ->first();

            // Calcular estimación de pago por milla
            $estimacionPagoPorMilla = $millasRecorridas > 0 ?
                round($productividad / $millasRecorridas, 2) : 0;

            // Preparar datos de respuesta
            $datosOperacion = [
                'periodo' => [
                    'fecha_inicial' => $fechaInicial->format('d/m/Y'),
                    'fecha_final' => $fechaFinal->format('d/m/Y')
                ],
                'datos_relevantes' => [
                    [
                        'ranking' => 1,
                        'item' => 'DÍAS TRANSCURRIDOS',
                        'total' => $diasTranscurridos,
                        'unidad' => 'días'
                    ],
                    [
                        'ranking' => 2,
                        'item' => 'PRODUCTIVIDAD',
                        'total' => number_format($productividad, 2),
                        'unidad' => '$',
                        'valor_numerico' => $productividad
                    ],
                    [
                        'ranking' => 3,
                        'item' => 'MILLAS RECORRIDAS EN SERVICIO',
                        'total' => number_format($millasRecorridas, 2),
                        'unidad' => 'millas'
                    ],
                    [
                        'ranking' => 4,
                        'item' => 'PRODUCTIVIDAD POR DÍA',
                        'total' => number_format($productividadPorDia, 2),
                        'unidad' => '$/día',
                        'valor_numerico' => $productividadPorDia
                    ],
                    [
                        'ranking' => 5,
                        'item' => 'CARGA MEJOR PAGADA',
                        'total' => $cargaMejorPagada ?
                            number_format($cargaMejorPagada->importe_total, 2) : '0.00',
                        'unidad' => '$',
                        'detalle' => $cargaMejorPagada ? [
                            'cliente' => $cargaMejorPagada->cliente_proveedor,
                            'fecha' => Carbon::parse($cargaMejorPagada->fecha)->format('d/m/Y'),
                            'destino' => $cargaMejorPagada->destino
                        ] : null
                    ],
                    [
                        'ranking' => 6,
                        'item' => 'ESTIMACIÓN PAGO POR MILLA',
                        'total' => number_format($estimacionPagoPorMilla, 2),
                        'unidad' => '$/milla',
                        'valor_numerico' => $estimacionPagoPorMilla
                    ]
                ]
            ];

            return response()->json([
                'success' => true,
                'data' => $datosOperacion
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener resumen estadístico adicional
     */
    public function getOperationSummary(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial)->startOfDay();
            $fechaFinal = Carbon::parse($request->fecha_final)->endOfDay();

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true);

            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            // Totales por tipo de transacción (ingresos excluyendo caja operativa, egresos todos)
            $totalIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                ->sum('importe_total');

            $totalEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->sum('importe_total');

            // Número de transacciones (ingresos excluyendo caja operativa, egresos todos)
            $numeroIngresos = $query->clone()
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                ->count();

            $numeroEgresos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->count();

            // Balance neto
            $balanceNeto = $totalIngresos - $totalEgresos;

            // Promedio de ingresos por transacción
            $promedioIngresos = $numeroIngresos > 0 ?
                round($totalIngresos / $numeroIngresos, 2) : 0;

            // Top categorías de gastos
            $topCategoriasGastos = $query->clone()
                ->where('tipo_de_transaccion', 'egreso')
                ->with('categoria')
                ->select('categoria_id', DB::raw('SUM(importe_total) as total'))
                ->groupBy('categoria_id')
                ->orderBy('total', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($item) {
                    return [
                        'categoria' => $item->categoria->nombre ?? 'Sin categoría',
                        'total' => number_format($item->total, 2)
                    ];
                });

            // Resumen por método de pago (todas las transacciones, sin filtro de caja para consistencia en métodos)
            $resumenMetodosPago = $query->clone()
                ->with('metodo')
                ->select(
                    'metodo_id',
                    DB::raw('COUNT(*) as cantidad'),
                    DB::raw('SUM(importe_total) as total')
                )
                ->groupBy('metodo_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'metodo' => $item->metodo->nombre ?? 'Sin método',
                        'cantidad' => $item->cantidad,
                        'total' => number_format($item->total, 2)
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2),
                        'total_egresos' => number_format($totalEgresos, 2),
                        'balance_neto' => number_format($balanceNeto, 2),
                        'numero_ingresos' => $numeroIngresos,
                        'numero_egresos' => $numeroEgresos,
                        'promedio_ingresos' => number_format($promedioIngresos, 2)
                    ],
                    'top_categorias_gastos' => $topCategoriasGastos,
                    'metodos_pago' => $resumenMetodosPago
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el resumen de operación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener gráfico de productividad diaria
     */
    public function getDailyProductivity(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
                'vehicle_id' => 'nullable|exists:vehicles,id'
            ]);

            $fechaInicial = Carbon::parse($request->fecha_inicial);
            $fechaFinal = Carbon::parse($request->fecha_final);

            $query = FinancialTransactions::query()
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->where('estado', true)
                ->where('tipo_de_transaccion', 'ingreso')
                ->whereNull('caja_operativa_id'); // EXCLUIR ingresos de caja operativa (recargas internas)

            if ($request->vehicle_id) {
                $query->where('vehicle_id', $request->vehicle_id);
            }

            // Agrupar por día
            $productividadDiaria = $query
                ->select(
                    DB::raw('DATE(fecha) as dia'),
                    DB::raw('SUM(importe_total) as total_dia'),
                    DB::raw('COUNT(*) as numero_transacciones'),
                    DB::raw('SUM(millas) as millas_dia')
                )
                ->groupBy('dia')
                ->orderBy('dia')
                ->get()
                ->map(function ($item) {
                    return [
                        'fecha' => Carbon::parse($item->dia)->format('d/m/Y'),
                        'dia_semana' => Carbon::parse($item->dia)->locale('es')->dayName,
                        'productividad' => round($item->total_dia, 2),
                        'transacciones' => $item->numero_transacciones,
                        'millas' => round($item->millas_dia, 2)
                    ];
                });

            // Calcular estadísticas
            $promedioProductividad = $productividadDiaria->avg('productividad');
            $maxProductividad = $productividadDiaria->max('productividad');
            $minProductividad = $productividadDiaria->min('productividad');

            return response()->json([
                'success' => true,
                'data' => [
                    'productividad_diaria' => $productividadDiaria,
                    'estadisticas' => [
                        'promedio' => round($promedioProductividad, 2),
                        'maximo' => round($maxProductividad, 2),
                        'minimo' => round($minProductividad, 2),
                        'dias_totales' => $productividadDiaria->count()
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar el reporte de productividad diaria',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar reporte a Excel (similar a tu imagen)
     */
    public function exportToExcel(Request $request)
    {
        try {
            // Primero obtener los datos del reporte
            $reportData = $this->getOperationReport($request);
            $data = json_decode($reportData->getContent(), true);

            if (!$data['success']) {
                throw new \Exception('Error al generar datos del reporte');
            }

            // Aquí podrías usar Laravel Excel o PHPSpreadsheet para generar el archivo
            // Por ahora retorno los datos formateados

            return response()->json([
                'success' => true,
                'message' => 'Datos listos para exportar',
                'data' => $data['data']
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar el reporte',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
