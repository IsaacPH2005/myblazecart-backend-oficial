<?php

namespace App\Http\Controllers\api\InvestorLeaseOn;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\Category;
use App\Models\FinancialTransactions;
use App\Models\Investment;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class EgresosPorCategoriaInvestorLeaseOn extends Controller
{
    /**
     * ============================================================================
     * OBTENER EGRESOS POR CATEGORÍA DEL INVERSIONISTA CON FILTRO OPCIONAL DE VEHÍCULO
     * ============================================================================
     */
    public function getExpensesByCategoryByBusiness(Request $request)
    {
        try {
            // ============== VALIDACIÓN DE PARÁMETROS ==============
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

            // ============== VERIFICAR QUE EL USUARIO TIENE INVERSIONES ACTIVAS ==============
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

            // ============== INFORMACIÓN DEL NEGOCIO ==============
            $negocio = Business::findOrFail($negocioId);

            if (!$negocio->estado) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'El negocio no está activo'
                ], 400);
            }

            // ============== INFORMACIÓN DEL VEHÍCULO ==============
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

                if (!$vehicle->is_active) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no está activo'
                    ], 400);
                }

                // Verificar inversión en el vehículo
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

            Log::info('🔍 Procesando egresos por categoría (INVERSIONISTA)', [
                'user_id' => $user->id,
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'filtrado_por_vehiculo' => $esFiltradoPorVehiculo,
                'fecha_rango' => [$fechaInicial, $fechaFinal]
            ]);

            // ============== CONSTRUIR CONSULTA BASE PARA EGRESOS ==============
            $query = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            // Aplicar filtro de vehículo si se especificó
            if ($esFiltradoPorVehiculo) {
                $query->where('vehicle_id', $vehicleId);
            }

            // ============== CALCULAR TOTALES GLOBALES ==============
            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();

            // ============== CALCULAR INFORMACIÓN DE INVERSIÓN ==============
            $totalInvertido = $inversiones->sum('monto_inversion');
            $porcentajeGastoVsInversion = $totalInvertido > 0 ? ($totalGlobal / $totalInvertido) * 100 : 0;

            Log::info('💰 Totales calculados (INVERSIONISTA)', [
                'total_global' => $totalGlobal,
                'cantidad_transacciones' => $cantidadGlobal,
                'total_invertido' => $totalInvertido,
                'porcentaje_gasto_vs_inversion' => $porcentajeGastoVsInversion
            ]);

            // ============== OBTENER EGRESOS AGRUPADOS POR CATEGORÍA ==============
            $egresos = $query->get()
                ->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalGlobal) {
                    $categoria = $categoriaGroup->first()->categoria;
                    $totalCategoria = $categoriaGroup->sum('importe_total');
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
            $todasCategorias = Category::all()
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
                ->sortByDesc('porcentaje')
                ->values()
                ->all();

            Log::info('📊 Categorías procesadas (INVERSIONISTA)', [
                'total_categorias' => count($todasCategorias),
                'categorias_con_egresos' => $egresosOrdenados->count()
            ]);

            // ============== DESGLOSE POR VEHÍCULO (SOLO SI NO HAY FILTRO DE VEHÍCULO) ==============
            $desglosePorVehiculo = [];
            if (!$esFiltradoPorVehiculo) {
                $desglosePorVehiculo = $this->getDesglosePorVehiculoInversionista($user->id, $negocioId, $fechaInicial, $fechaFinal);
                Log::info('🚗 Desglose por vehículo generado (INVERSIONISTA)', [
                    'cantidad_vehiculos' => count($desglosePorVehiculo)
                ]);
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
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => strtoupper($negocio->nombre)
                    ],
                    'filtro' => [
                        'por_vehiculo' => $esFiltradoPorVehiculo,
                        'vehicle_id' => $vehicleId,
                    ],
                    'inversion' => [
                        'total_invertido' => floatval($totalInvertido),
                        'total_invertido_formateado' => number_format($totalInvertido, 2, '.', ','),
                        'cantidad_inversiones' => $inversiones->count(),
                        'porcentaje_gasto_vs_inversion' => round($porcentajeGastoVsInversion, 2),
                    ],
                    'resumen_global' => [
                        'total_egresos' => floatval($totalGlobal),
                        'cantidad_transacciones' => $cantidadGlobal,
                        'promedio_egreso' => $cantidadGlobal > 0 ? floatval($totalGlobal / $cantidadGlobal) : 0.0,
                    ],
                    'categorias' => $todasCategorias,
                    'estadisticas_adicionales' => [
                        'categoria_mayor_egreso' => $egresosOrdenados->first(),
                        'categoria_menor_egreso' => $egresosOrdenados->filter(fn($cat) => $cat['total_categoria'] > 0)->last(),
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

            // Agregar desglose por vehículo si NO hay filtro de vehículo
            if (!$esFiltradoPorVehiculo && count($desglosePorVehiculo) > 0) {
                $response['data']['desglose_por_vehiculo'] = $desglosePorVehiculo;
            }

            Log::info('✅ Egresos por categoría procesados exitosamente (INVERSIONISTA)', [
                'user_id' => $user->id,
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'total_egresos' => $totalGlobal,
                'cantidad_categorias' => count($todasCategorias)
            ]);

            return response()->json($response, 200);
        } catch (\Exception $e) {
            Log::error('❌ Error al obtener egresos por categoría (INVERSIONISTA)', [
                'user_id' => $user->id ?? null,
                'negocio_id' => $negocioId ?? null,
                'vehicle_id' => $vehicleId ?? null,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
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
     * OBTENER DESGLOSE DE EGRESOS POR VEHÍCULO Y SUS CATEGORÍAS (SOLO VEHÍCULOS DONDE INVIRTIÓ)
     * ============================================================================
     */
    private function getDesglosePorVehiculoInversionista($userId, $negocioId, $fechaInicial, $fechaFinal)
    {
        // Obtener SOLO los vehículos donde el inversionista tiene inversión activa
        $inversionesVehiculos = Investment::where('user_id', $userId)
            ->where('business_id', $negocioId)
            ->where('active', true)
            ->whereNotNull('vehicle_id')
            ->with(['vehicle' => function ($query) {
                $query->where('is_active', true);
            }])
            ->get();

        if ($inversionesVehiculos->isEmpty()) {
            return [];
        }

        $desglose = [];

        foreach ($inversionesVehiculos as $inversion) {
            $vehiculo = $inversion->vehicle;

            if (!$vehiculo) {
                continue;
            }

            // Obtener egresos del vehículo en el período
            $egresosVehiculo = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('vehicle_id', $vehiculo->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with('categoria')
                ->get();

            $totalEgresosVehiculo = $egresosVehiculo->sum('importe_total');
            $cantidadTransacciones = $egresosVehiculo->count();

            // Información de inversión en este vehículo
            $montoInvertidoVehiculo = $inversion->monto_inversion;
            $porcentajeGastoVsInversionVehiculo = $montoInvertidoVehiculo > 0
                ? ($totalEgresosVehiculo / $montoInvertidoVehiculo) * 100
                : 0;

            // Si el vehículo no tiene egresos, igual lo incluimos con valores en 0
            if ($cantidadTransacciones === 0) {
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
                    'inversion' => [
                        'monto_invertido' => floatval($montoInvertidoVehiculo),
                        'monto_invertido_formateado' => number_format($montoInvertidoVehiculo, 2, '.', ','),
                        'porcentaje_gasto_vs_inversion' => 0.0,
                    ],
                    'resumen' => [
                        'total_egresos' => 0.0,
                        'cantidad_transacciones' => 0,
                        'promedio_egreso' => 0.0,
                    ],
                    'categorias' => [],
                ];
                continue;
            }

            // Agrupar por categoría
            $egresosPorCategoria = $egresosVehiculo->groupBy('categoria_id')
                ->map(function ($categoriaGroup, $categoriaId) use ($totalEgresosVehiculo) {
                    $categoria = $categoriaGroup->first()->categoria;
                    $totalCategoria = $categoriaGroup->sum('importe_total');
                    $porcentaje = $totalEgresosVehiculo > 0
                        ? ($totalCategoria / $totalEgresosVehiculo) * 100
                        : 0;

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
                'inversion' => [
                    'monto_invertido' => floatval($montoInvertidoVehiculo),
                    'monto_invertido_formateado' => number_format($montoInvertidoVehiculo, 2, '.', ','),
                    'porcentaje_gasto_vs_inversion' => round($porcentajeGastoVsInversionVehiculo, 2),
                ],
                'resumen' => [
                    'total_egresos' => floatval($totalEgresosVehiculo),
                    'cantidad_transacciones' => $cantidadTransacciones,
                    'promedio_egreso' => $cantidadTransacciones > 0
                        ? floatval($totalEgresosVehiculo / $cantidadTransacciones)
                        : 0.0,
                ],
                'categorias' => $egresosPorCategoria,
            ];
        }

        // Ordenar vehículos por total de egresos (de mayor a menor)
        usort($desglose, function ($a, $b) {
            return $b['resumen']['total_egresos'] <=> $a['resumen']['total_egresos'];
        });

        return $desglose;
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

    /**
     * ============================================================================
     * EXPORTAR EGRESOS A EXCEL (INVERSIONISTA)
     * ============================================================================
     */
    public function exportExpensesToExcel(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'negocio_id' => 'required|exists:businesses,id',
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

            $user = Auth::user();
            $negocioId = $request->input('negocio_id');
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Verificar inversión
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
            $vehicle = $vehicleId ? Vehicle::find($vehicleId) : null;

            $query = FinancialTransactions::where('negocio_id', $negocioId)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'negocio', 'vehicle']);

            if ($vehicleId) $query->where('vehicle_id', $vehicleId);

            $totalGlobal = $query->sum('importe_total');
            $cantidadGlobal = $query->count();
            $totalInvertido = $inversiones->sum('monto_inversion');

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

            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            $sheet->setCellValue('A1', 'REPORTE DE EGRESOS POR CATEGORÍA - INVERSIONISTA');
            $sheet->mergeCells('A1:F1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('A1')->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF0066CC');
            $sheet->getStyle('A1')->getFont()->getColor()->setARGB('FFFFFFFF');

            $row = 3;
            $sheet->setCellValue("A{$row}", 'Inversionista:');
            $sheet->setCellValue("B{$row}", $user->name ?? $user->email);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue("A{$row}", 'Período:');
            $sheet->setCellValue("B{$row}", "{$fechaInicial} al {$fechaFinal}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue("A{$row}", 'Negocio:');
            $sheet->setCellValue("B{$row}", $negocio->nombre);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            if ($vehicle) {
                $row++;
                $sheet->setCellValue("A{$row}", 'Vehículo:');
                $sheet->setCellValue("B{$row}", "{$vehicle->codigo_unico} - {$vehicle->numero_placa}");
                $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            }

            $row++;
            $sheet->setCellValue("A{$row}", 'Mi Inversión Total:');
            $sheet->setCellValue("B{$row}", '$' . number_format($totalInvertido, 2));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setARGB('FF00AA00');

            $row++;
            $sheet->setCellValue("A{$row}", 'Total Egresos:');
            $sheet->setCellValue("B{$row}", '$' . number_format($totalGlobal, 2));
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getStyle("B{$row}")->getFont()->setBold(true)->getColor()->setARGB('FFFF0000');

            $row++;
            $sheet->setCellValue("A{$row}", 'Cantidad Transacciones:');
            $sheet->setCellValue("B{$row}", $cantidadGlobal);
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);

            $row += 2;
            $headerRow = $row;

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

            $row++;
            $num = 1;
            foreach ($egresos as $egreso) {
                $sheet->setCellValue("A{$row}", $num++);
                $sheet->setCellValue("B{$row}", $egreso['categoria_nombre']);
                $sheet->setCellValue("C{$row}", '$' . number_format($egreso['total_categoria'], 2));
                $sheet->setCellValue("D{$row}", number_format($egreso['porcentaje'], 2) . '%');
                $sheet->setCellValue("E{$row}", $egreso['cantidad_transacciones']);
                $sheet->setCellValue("F{$row}", '$' . number_format($egreso['promedio_egreso'], 2));

                $sheet->getStyle("A{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                $sheet->getStyle("C{$row}:F{$row}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

                $row++;
            }

            $lastRow = $row - 1;
            $sheet->getStyle("A{$headerRow}:F{$lastRow}")->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN);

            $sheet->getColumnDimension('A')->setWidth(8);
            $sheet->getColumnDimension('B')->setWidth(25);
            $sheet->getColumnDimension('C')->setWidth(18);
            $sheet->getColumnDimension('D')->setWidth(15);
            $sheet->getColumnDimension('E')->setWidth(15);
            $sheet->getColumnDimension('F')->setWidth(18);

            $fileName = 'Egresos_Por_Categoria_Inversionista_' . date('Y-m-d_His') . '.xlsx';
            $tempFile = tempnam(sys_get_temp_dir(), $fileName);

            $writer = new Xlsx($spreadsheet);
            $writer->save($tempFile);

            Log::info('✅ Excel de egresos generado (INVERSIONISTA)', [
                'user_id' => $user->id,
                'negocio_id' => $negocioId,
                'vehicle_id' => $vehicleId,
                'archivo' => $fileName
            ]);

            return response()->download($tempFile, $fileName, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])->deleteFileAfterSend(true);
        } catch (\Exception $e) {
            Log::error('❌ Error al exportar egresos a Excel (INVERSIONISTA)', [
                'user_id' => $user->id ?? null,
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
