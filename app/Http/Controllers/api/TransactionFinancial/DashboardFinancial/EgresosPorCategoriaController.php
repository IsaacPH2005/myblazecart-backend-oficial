<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

class EgresosPorCategoriaController extends Controller
{
    /**
     * ============================================================================
     * OBTENER EGRESOS POR CATEGORÍA CON FILTRO OPCIONAL DE VEHÍCULO
     * ============================================================================
     *
     * Este método maneja TRES casos:
     * 1. Egresos GLOBALES (sin filtro de negocio ni vehículo)
     * 2. Egresos POR NEGOCIO (con negocio_id, sin vehicle_id)
     * 3. Egresos POR VEHÍCULO (con negocio_id y vehicle_id)
     *
     * PARÁMETROS:
     * - negocio_id: ID del negocio (opcional - si no se envía, muestra todos)
     * - vehicle_id: ID del vehículo (opcional - para filtrar por vehículo específico)
     * - fecha_inicial: Fecha de inicio (obligatorio)
     * - fecha_final: Fecha de fin (obligatorio)
     *
     * @param Request $request
     */
    public function getExpensesByCategoryByBusiness(Request $request)
    {
        try {
            // ============== VALIDACIÓN DE PARÁMETROS ==============
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'nullable|exists:businesses,id',
                'vehicle_id' => 'nullable|exists:vehicles,id',
                'fecha_inicial' => 'required|date',
                'fecha_final' => 'required|date|after_or_equal:fecha_inicial',
            ], [
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

            // ============== INFORMACIÓN DEL NEGOCIO ==============
            $negocio = null;
            if ($negocioId) {
                $negocio = Business::find($negocioId);
            }

            // ============== INFORMACIÓN DEL VEHÍCULO ==============
            $vehicle = null;
            $esFiltradoPorVehiculo = !is_null($vehicleId);

            if ($esFiltradoPorVehiculo) {
                $vehicle = Vehicle::findOrFail($vehicleId);

                // Verificar que el vehículo pertenece al negocio (si se especificó negocio)
                if ($negocioId && $vehicle->negocio_id != $negocioId) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece al negocio seleccionado'
                    ], 400);
                }

                // Si no se especificó negocio pero sí vehículo, usar el negocio del vehículo
                if (!$negocioId) {
                    $negocioId = $vehicle->negocio_id;
                    $negocio = Business::find($negocioId);
                }
            }

            Log::info('Procesando egresos por categoría', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'filtrado_por_vehiculo' => $esFiltradoPorVehiculo,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CONSTRUIR CONSULTA BASE PARA EGRESOS ==============
            $query = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            // Aplicar filtro de negocio si se especificó
            if ($negocioId) {
                $query->where('negocio_id', $negocioId);
            }

            // Aplicar filtro de vehículo si se especificó
            if ($esFiltradoPorVehiculo) {
                $query->where('vehicle_id', $vehicleId);
            }

            // ============== CALCULAR TOTALES GLOBALES ==============
            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            // ============== OBTENER EGRESOS AGRUPADOS POR CATEGORÍA ==============
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
                        'total_categoria' => floatval($totalCategoria),
                        'porcentaje' => round($porcentaje, 2),
                        'cantidad_transacciones' => $categoriaGroup->count(),
                        'promedio_egreso' => floatval($categoriaGroup->avg('importe_total')),
                    ];
                });

            // Ordenar categorías por total de egresos (de mayor a menor)
            $egresosOrdenados = $egresos->sortByDesc('total_categoria');

            // ============== OBTENER TODAS LAS CATEGORÍAS ==============
            $todasCategorias = \App\Models\Category::all()
                ->map(function ($categoria) use ($egresosOrdenados) {
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
                            'total_categoria' => 0.0,
                            'porcentaje' => 0.0,
                            'cantidad_transacciones' => 0,
                            'promedio_egreso' => 0.0,
                        ];
                    }
                })
                ->sortByDesc('porcentaje');

            // ============== DESGLOSE POR VEHÍCULO (SOLO SI NO HAY FILTRO DE VEHÍCULO) ==============
            $desglosePorVehiculo = [];
            if (!$esFiltradoPorVehiculo && $negocioId) {
                $desglosePorVehiculo = $this->getDesglosePorVehiculo($negocioId, $fechaInicial, $fechaFinal);
            }

            // ============== PREPARAR RESPUESTA ==============
            $response = [
                'status' => 'success',
                'message' => $esFiltradoPorVehiculo
                    ? 'Egresos por categoría del vehículo obtenidos correctamente'
                    : 'Egresos por categoría obtenidos correctamente',
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
                    'filtro' => [
                        'por_vehiculo' => $esFiltradoPorVehiculo,
                        'vehicle_id' => $vehicleId,
                    ],
                    'resumen_global' => [
                        'total_egresos' => floatval($totalGlobal),
                        'cantidad_transacciones' => $cantidadGlobal,
                        'promedio_egreso' => $cantidadGlobal > 0 ? floatval($totalGlobal / $cantidadGlobal) : 0.0,
                    ],
                    'categorias' => $todasCategorias->values()->all(),
                    'estadisticas_adicionales' => [
                        'categoria_mayor_egreso' => $egresosOrdenados->first(),
                        'categoria_menor_egreso' => $egresosOrdenados->filter(fn($cat) => $cat['total_categoria'] > 0)->last(),
                        'negocio_mayor_egreso' => $negocioId ? null : $this->getNegocioMayorEgreso($fechaInicial, $fechaFinal),
                        'distribucion_porcentual' => $this->getDistribucionPorcentual($egresosOrdenados, $totalGlobal)
                    ]
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            // Agregar información del vehículo si está filtrado
            if ($esFiltradoPorVehiculo && $vehicle) {
                $response['data']['vehiculo'] = [
                    'id' => $vehicle->id,
                    'codigo_unico' => $vehicle->codigo_unico,
                    'numero_placa' => $vehicle->numero_placa,
                    'marca' => $vehicle->marca,
                    'modelo' => $vehicle->modelo,
                    'año' => $vehicle->año,
                    'tipo_vehiculo' => $vehicle->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehicle->tipo_propiedad),
                ];
            }

            // Agregar desglose por vehículo si NO hay filtro de vehículo y hay negocio
            if (!$esFiltradoPorVehiculo && $negocioId && count($desglosePorVehiculo) > 0) {
                $response['data']['desglose_por_vehiculo'] = $desglosePorVehiculo;
            }

            Log::info('Egresos por categoría procesados exitosamente', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'total_egresos' => $totalGlobal,
                'cantidad_categorias_con_egresos' => $egresosOrdenados->count()
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('Error al obtener egresos por categoría', [
                'negocio_id' => $negocioId ?? null,
                'vehicle_id' => $vehicleId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener egresos por categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ============================================================================
     * OBTENER DESGLOSE DE EGRESOS POR VEHÍCULO
     * ============================================================================
     *
     * @param int $negocioId
     * @param string $fechaInicial
     * @param string $fechaFinal
     * @return array
     */
    private function getDesglosePorVehiculo($negocioId, $fechaInicial, $fechaFinal)
    {
        // Obtener todos los vehículos del negocio que tienen egresos en el período
        $vehiculosConEgresos = FinancialTransactions::where('negocio_id', $negocioId)
            ->where('tipo_de_transaccion', 'Egreso')
            ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
            ->whereNotNull('vehicle_id')
            ->select('vehicle_id')
            ->distinct()
            ->pluck('vehicle_id');

        if ($vehiculosConEgresos->isEmpty()) {
            return [];
        }

        $vehiculos = Vehicle::whereIn('id', $vehiculosConEgresos)->get();

        $desglose = [];

        foreach ($vehiculos as $vehiculo) {
            // Obtener egresos del vehículo por categoría
            $egresosVehiculo = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('vehicle_id', $vehiculo->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with('categoria')
                ->get();

            $totalEgresosVehiculo = $egresosVehiculo->sum('importe_total');
            $cantidadTransacciones = $egresosVehiculo->count();

            // Agrupar por categoría
            $egresosPorCategoria = $egresosVehiculo->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalEgresosVehiculo) {
                    $categoria = $categoriaGroup->first()->categoria;
                    $totalCategoria = $categoriaGroup->sum('importe_total');
                    $porcentaje = $totalEgresosVehiculo > 0 ? ($totalCategoria / $totalEgresosVehiculo) * 100 : 0;

                    return [
                        'categoria_id' => $categoriaId,
                        'categoria_nombre' => $categoria->nombre ?? 'Sin categoría',
                        'total_categoria' => floatval($totalCategoria),
                        'porcentaje' => round($porcentaje, 2),
                        'cantidad_transacciones' => $categoriaGroup->count(),
                        'promedio_egreso' => floatval($categoriaGroup->avg('importe_total')),
                    ];
                })
                ->sortByDesc('total_categoria')
                ->values()
                ->all();

            $desglose[] = [
                'vehiculo' => [
                    'id' => $vehiculo->id,
                    'codigo_unico' => $vehiculo->codigo_unico,
                    'numero_placa' => $vehiculo->numero_placa,
                    'marca' => $vehiculo->marca,
                    'modelo' => $vehiculo->modelo,
                    'año' => $vehiculo->año,
                    'tipo_vehiculo' => $vehiculo->tipo_vehiculo,
                    'tipo_propiedad' => strtoupper($vehiculo->tipo_propiedad),
                ],
                'resumen' => [
                    'total_egresos' => floatval($totalEgresosVehiculo),
                    'cantidad_transacciones' => $cantidadTransacciones,
                    'promedio_egreso' => $cantidadTransacciones > 0 ? floatval($totalEgresosVehiculo / $cantidadTransacciones) : 0.0,
                ],
                'categorias' => $egresosPorCategoria,
            ];
        }

        // Ordenar vehículos por total de egresos (mayor a menor)
        usort($desglose, function ($a, $b) {
            return $b['resumen']['total_egresos'] <=> $a['resumen']['total_egresos'];
        });

        return $desglose;
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
            'total_egresos' => floatval($negocioMayor->total_egresos)
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

        return $egresos->filter(function ($categoria) {
            return $categoria['total_categoria'] > 0;
        })->map(function ($categoria) use ($totalGlobal) {
            return [
                'categoria_id' => $categoria['categoria_id'],
                'categoria_nombre' => $categoria['categoria_nombre'],
                'total_categoria' => floatval($categoria['total_categoria']),
                'porcentaje' => round(($categoria['total_categoria'] / $totalGlobal) * 100, 2)
            ];
        })->sortByDesc('porcentaje')->values()->all();
    }
    public function exportExpensesToExcel(Request $request)
    {
        try {
            // Validar parámetros
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

            // Obtener datos
            $negocio = $negocioId ? Business::find($negocioId) : null;
            $vehicle = $vehicleId ? Vehicle::find($vehicleId) : null;

            // Construir consulta
            $query = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            if ($negocioId) $query->where('negocio_id', $negocioId);
            if ($vehicleId) $query->where('vehicle_id', $vehicleId);

            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            // Agrupar por categoría
            $egresos = $query->get()
                ->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalGlobal) {
                    $categoria = $categoriaGroup->first()->categoria;
                    $totalCategoria = $categoriaGroup->sum('importe_total');
                    $porcentaje = $totalGlobal > 0 ? ($totalCategoria / $totalGlobal) * 100 : 0;

                    return [
                        'categoria_nombre' => $categoria->nombre ?? 'Sin categoría',
                        'total_categoria' => $totalCategoria,
                        'porcentaje' => $porcentaje,
                        'cantidad_transacciones' => $categoriaGroup->count(),
                        'promedio_egreso' => $categoriaGroup->avg('importe_total'),
                    ];
                })
                ->sortByDesc('total_categoria')
                ->values();

            // Crear Excel
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // ========== ENCABEZADO ==========
            $sheet->setCellValue('A1', 'REPORTE DE EGRESOS POR CATEGORÍA');
            $sheet->mergeCells('A1:F1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF0066CC');
            $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');

            // Información del reporte
            $row = 3;
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", "{$fechaInicial} al {$fechaFinal}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue("A{$row}", 'Negocio:');
            $sheet->setCellValue("B{$row}", $negocio ? $negocio->nombre : 'Global (Todos)');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            if ($vehicle) {
                $row++;
                $sheet->setCellValue("A{$row}", 'Vehículo:');
                $sheet->setCellValue("B{$row}", "{$vehicle->codigo_unico} - {$vehicle->numero_placa}");
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            }

            $row++;
            $sheet->setCellValue("A{$row}", 'Total Egresos:');
            $sheet->setCellValue("B{$row}", '$' . number_format($totalGlobal, 2));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFF0000');

            $row++;
            $sheet->setCellValue("A{$row}", 'Cantidad Transacciones:');
            $sheet->setCellValue("B{$row}", $cantidadGlobal);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            // ========== TABLA DE DATOS ==========
            $row += 2;
            $headerRow = $row;

            // Headers
            $headers = ['#', 'Categoría', 'Total Egresos', '% del Total', 'Cantidad Tx.', 'Promedio'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue("{$col}{$row}", $header);
                $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("{$col}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD9E1F2');
                $sheet->getStyle("{$col}{$row}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $col++;
            }

            // Datos
            $row++;
            $num = 1;
            foreach ($egresos as $egreso) {
                $sheet->setCellValue("A{$row}", $num++);
                $sheet->setCellValue("B{$row}", $egreso['categoria_nombre']);
                $sheet->setCellValue("C{$row}", '$' . number_format($egreso['total_categoria'], 2));
                $sheet->setCellValue("D{$row}", number_format($egreso['porcentaje'], 2) . '%');
                $sheet->setCellValue("E{$row}", $egreso['cantidad_transacciones']);
                $sheet->setCellValue("F{$row}", '$' . number_format($egreso['promedio_egreso'], 2));

                // Alineación
                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $row++;
            }

            // ========== ESTILOS Y BORDES ==========
            $lastRow = $row - 1;
            $sheet->getStyle("A{$headerRow}:F{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            // Ajustar ancho de columnas
            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);
            $sheet->getColumnDimension('F')->setWidth(18);

            // ========== GUARDAR Y DESCARGAR ==========
            $fileName = 'Egresos_Por_Categoria_' . date('Y-m-d_His') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), $fileName);

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            Log::info('Excel de egresos generado exitosamente', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'archivo' => $fileName
            ]);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error al exportar egresos a Excel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function exportIncomesToExcel(Request $request)
    {
        try {
            // Validar parámetros
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

            // Obtener datos
            $negocio = $negocioId ? Business::find($negocioId) : null;
            $vehicle = $vehicleId ? Vehicle::find($vehicleId) : null;

            // Construir consulta (INGRESOS - sin caja operativa)
            $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
                ->whereNull('caja_operativa_id') // Solo ingresos NO de caja
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            if ($negocioId) $query->where('negocio_id', $negocioId);
            if ($vehicleId) $query->where('vehicle_id', $vehicleId);

            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            // Agrupar por categoría
            $ingresos = $query->get()
                ->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalGlobal) {
                    $categoria = $categoriaGroup->first()->categoria;
                    $totalCategoria = $categoriaGroup->sum('importe_total');
                    $porcentaje = $totalGlobal > 0 ? ($totalCategoria / $totalGlobal) * 100 : 0;

                    return [
                        'categoria_nombre' => $categoria->nombre ?? 'Sin categoría',
                        'total_categoria' => $totalCategoria,
                        'porcentaje' => $porcentaje,
                        'cantidad_transacciones' => $categoriaGroup->count(),
                        'promedio_ingreso' => $categoriaGroup->avg('importe_total'),
                    ];
                })
                ->sortByDesc('total_categoria')
                ->values();

            // Crear Excel
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // ========== ENCABEZADO ==========
            $sheet->setCellValue('A1', 'REPORTE DE INGRESOS POR CATEGORÍA');
            $sheet->mergeCells('A1:F1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF10B981'); // Verde
            $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');

            // Información del reporte
            $row = 3;
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", "{$fechaInicial} al {$fechaFinal}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue("A{$row}", 'Negocio:');
            $sheet->setCellValue("B{$row}", $negocio ? $negocio->nombre : 'Global (Todos)');
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            if ($vehicle) {
                $row++;
                $sheet->setCellValue("A{$row}", 'Vehículo:');
                $sheet->setCellValue("B{$row}", "{$vehicle->codigo_unico} - {$vehicle->numero_placa}");
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            }

            $row++;
            $sheet->setCellValue("A{$row}", 'Total Ingresos:');
            $sheet->setCellValue("B{$row}", '$' . number_format($totalGlobal, 2));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF10B981');

            $row++;
            $sheet->setCellValue("A{$row}", 'Cantidad Transacciones:');
            $sheet->setCellValue("B{$row}", $cantidadGlobal);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            // ========== TABLA DE DATOS ==========
            $row += 2;
            $headerRow = $row;

            // Headers
            $headers = ['#', 'Categoría', 'Total Ingresos', '% del Total', 'Cantidad Tx.', 'Promedio'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue("{$col}{$row}", $header);
                $sheet->getStyle("{$col}{$row}")->getFont()->setBold(true);
                $sheet->getStyle("{$col}{$row}")->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFD1FAE5'); // Verde claro
                $sheet->getStyle("{$col}{$row}")->getAlignment()
                    ->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $col++;
            }

            // Datos
            $row++;
            $num = 1;
            foreach ($ingresos as $ingreso) {
                $sheet->setCellValue("A{$row}", $num++);
                $sheet->setCellValue("B{$row}", $ingreso['categoria_nombre']);
                $sheet->setCellValue("C{$row}", '$' . number_format($ingreso['total_categoria'], 2));
                $sheet->setCellValue("D{$row}", number_format($ingreso['porcentaje'], 2) . '%');
                $sheet->setCellValue("E{$row}", $ingreso['cantidad_transacciones']);
                $sheet->setCellValue("F{$row}", '$' . number_format($ingreso['promedio_ingreso'], 2));

                // Alineación
                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $row++;
            }

            // ========== ESTILOS Y BORDES ==========
            $lastRow = $row - 1;
            $sheet->getStyle("A{$headerRow}:F{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            // Ajustar ancho de columnas
            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);
            $sheet->getColumnDimension('F')->setWidth(18);

            // ========== GUARDAR Y DESCARGAR ==========
            $fileName = 'Ingresos_Por_Categoria_' . date('Y-m-d_His') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), $fileName);

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            Log::info('Excel de ingresos generado exitosamente', [
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'archivo' => $fileName
            ]);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('Error al exportar ingresos a Excel', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
