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
use Carbon\Carbon;

class IngresosPorCategoriaInvestorLeaseOn extends Controller
{
    /**
     * ============================================================================
     * OBTENER INGRESOS POR CATEGORÍA DEL INVERSIONISTA
     * ============================================================================
     */
    public function getIncomesByCategoryByBusiness(Request $request)
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

            // ============== VERIFICAR QUE EL USUARIO ES INVERSIONISTA DEL NEGOCIO ==============
            $inversion = Investment::where('negocio_id', $negocioId)
                ->where('inversionista_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->first();

            if (!$inversion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversiones activas en este negocio',
                ], 403);
            }

            // ============== OBTENER INFORMACIÓN DEL NEGOCIO ==============
            $negocio = Business::find($negocioId);

            // ============== OBTENER INFORMACIÓN DEL VEHÍCULO (SI APLICA) ==============
            $vehiculo = null;
            if ($vehicleId) {
                $vehiculo = Vehicle::where('id', $vehicleId)
                    ->where('negocio_id', $negocioId)
                    ->first();

                if (!$vehiculo) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'El vehículo no pertenece a este negocio',
                    ], 422);
                }

                // Verificar inversión en el vehículo
                $inversionVehiculo = Investment::where('negocio_id', $negocioId)
                    ->where('inversionista_id', $user->id)
                    ->where('vehicle_id', $vehicleId)
                    ->where('estado', 'ACTIVO')
                    ->first();

                if (!$inversionVehiculo) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'No tienes inversiones activas en este vehículo',
                    ], 403);
                }
            }

            // ============== CONSTRUIR QUERY BASE ==============
            $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
                ->where('negocio_id', $negocioId)
                ->whereNull('caja_operativa_id')
                ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                ->with(['categoria', 'vehicle']);

            // Aplicar filtro de vehículo si existe
            if ($vehicleId) {
                $query->where('vehicle_id', $vehicleId);
            } else {
                // Si no hay filtro de vehículo, solo mostrar ingresos de vehículos donde tiene inversión
                $vehiculosConInversion = Investment::where('negocio_id', $negocioId)
                    ->where('inversionista_id', $user->id)
                    ->where('estado', 'ACTIVO')
                    ->whereNotNull('vehicle_id')
                    ->pluck('vehicle_id')
                    ->toArray();

                if (!empty($vehiculosConInversion)) {
                    $query->whereIn('vehicle_id', $vehiculosConInversion);
                }
            }

            $transacciones = $query->get();

            // ============== AGRUPAR POR CATEGORÍA ==============
            $categorias = $transacciones->groupBy('categoria_id')->map(function ($grupo, $categoriaId) {
                $categoria = $grupo->first()->categoria;
                $totalCategoria = $grupo->sum('importe_total');
                $cantidadTransacciones = $grupo->count();

                return [
                    'categoria_id' => $categoriaId,
                    'categoria_nombre' => $categoria ? $categoria->nombre : 'Sin categoría',
                    'total_categoria' => $totalCategoria,
                    'cantidad_transacciones' => $cantidadTransacciones,
                    'promedio_transaccion' => $cantidadTransacciones > 0 ? $totalCategoria / $cantidadTransacciones : 0,
                ];
            })->values()->sortByDesc('total_categoria')->values();

            // ============== RESUMEN GLOBAL ==============
            $totalIngresos = $transacciones->sum('importe_total');
            $cantidadTransacciones = $transacciones->count();

            $resumenGlobal = [
                'total_ingresos' => $totalIngresos,
                'cantidad_transacciones' => $cantidadTransacciones,
                'promedio_ingreso' => $cantidadTransacciones > 0 ? $totalIngresos / $cantidadTransacciones : 0,
            ];

            // ============== ESTADÍSTICAS ADICIONALES ==============
            $estadisticasAdicionales = [
                'categoria_mayor_ingreso' => $categorias->first() ?? null,
                'categoria_menor_ingreso' => $categorias->last() ?? null,
                'cantidad_categorias' => $categorias->count(),
            ];

            // ============== INFORMACIÓN DE INVERSIÓN ==============
            $totalInvertido = Investment::where('negocio_id', $negocioId)
                ->where('inversionista_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->when($vehicleId, function ($q) use ($vehicleId) {
                    return $q->where('vehicle_id', $vehicleId);
                })
                ->sum('monto_invertido');

            $cantidadInversiones = Investment::where('negocio_id', $negocioId)
                ->where('inversionista_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->when($vehicleId, function ($q) use ($vehicleId) {
                    return $q->where('vehicle_id', $vehicleId);
                })
                ->count();

            $porcentajeIngresoVsInversion = $totalInvertido > 0
                ? round(($totalIngresos / $totalInvertido) * 100, 2)
                : 0;

            $inversionInfo = [
                'total_invertido' => $totalInvertido,
                'total_invertido_formateado' => number_format($totalInvertido, 2, '.', ','),
                'cantidad_inversiones' => $cantidadInversiones,
                'porcentaje_ingreso_vs_inversion' => $porcentajeIngresoVsInversion,
            ];

            // ============== INFORMACIÓN DEL VEHÍCULO ==============
            $vehiculoInfo = null;
            if ($vehiculo) {
                $vehiculoInfo = [
                    'id' => $vehiculo->id,
                    'codigo_unico' => $vehiculo->codigo_unico ?? 'N/A',
                    'numero_placa' => $vehiculo->numero_placa,
                    'marca' => $vehiculo->marca,
                    'modelo' => $vehiculo->modelo,
                    'año' => $vehiculo->año,
                    'tipo_vehiculo' => $vehiculo->tipo_vehiculo,
                    'tipo_propiedad' => $vehiculo->tipo_propiedad,
                ];
            }

            // ============== RESPUESTA FINAL ==============
            return response()->json([
                'status' => 'success',
                'message' => 'Ingresos por categoría obtenidos correctamente',
                'data' => [
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias' => Carbon::parse($fechaInicial)->diffInDays(Carbon::parse($fechaFinal)) + 1,
                    ],
                    'negocio' => [
                        'id' => $negocio->id,
                        'nombre' => $negocio->nombre,
                    ],
                    'vehiculo' => $vehiculoInfo,
                    'inversion' => $inversionInfo,
                    'resumen_global' => $resumenGlobal,
                    'categorias' => $categorias,
                    'estadisticas_adicionales' => $estadisticasAdicionales,
                ],
                'timestamp' => now()->toDateTimeString(),
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error en getIncomesByCategoryByBusiness: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener ingresos por categoría',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================================
     * EXPORTAR INGRESOS POR CATEGORÍA A EXCEL
     * ============================================================================
     */
    public function exportIncomesByCategoryToExcel(Request $request)
    {
        try {
            if (!Auth::check()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Usuario no autenticado',
                ], 401);
            }

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
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = Auth::user();
            $negocioId = $request->input('negocio_id');
            $vehicleId = $request->input('vehicle_id');
            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Verificar inversión
            $inversion = Investment::where('negocio_id', $negocioId)
                ->where('inversionista_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->first();

            if (!$inversion) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No tienes inversiones activas en este negocio',
                ], 403);
            }

            // Obtener datos procesados
            $datos = $this->getProcessedIncomesByCategoryData($request);

            // Crear Excel
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            // ============== ENCABEZADO ==============
            $sheet->setCellValue('A1', 'REPORTE DE INGRESOS POR CATEGORÍA - INVERSIONISTA');
            $sheet->mergeCells('A1:F1');
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
            $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            // Información del negocio
            $row = 3;
            $sheet->setCellValue('A' . $row, 'Negocio:');
            $sheet->setCellValue('B' . $row, $datos['negocio']->nombre);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue('A' . $row, 'Período:');
            $sheet->setCellValue('B' . $row, $fechaInicial . ' - ' . $fechaFinal);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue('A' . $row, 'Fecha de generación:');
            $sheet->setCellValue('B' . $row, now()->format('d/m/Y H:i:s'));
            $sheet->getStyle('A' . $row)->getFont()->setBold(true);

            // Información de inversión
            $row += 2;
            $sheet->setCellValue('A' . $row, 'MI INVERSIÓN');
            $sheet->mergeCells('A' . $row . ':F' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('4CAF50');

            $row++;
            $sheet->setCellValue('A' . $row, 'Total Invertido:');
            $sheet->setCellValue('B' . $row, '$' . number_format($datos['inversion']['total_invertido'], 2));
            $sheet->setCellValue('C' . $row, 'Cantidad Inversiones:');
            $sheet->setCellValue('D' . $row, $datos['inversion']['cantidad_inversiones']);
            $sheet->getStyle('A' . $row . ':D' . $row)->getFont()->setBold(true);

            // Información del vehículo si aplica
            if ($datos['vehiculo']) {
                $row += 2;
                $sheet->setCellValue('A' . $row, 'VEHÍCULO FILTRADO');
                $sheet->mergeCells('A' . $row . ':F' . $row);
                $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
                $sheet->getStyle('A' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('2196F3');

                $row++;
                $sheet->setCellValue('A' . $row, 'Código:');
                $sheet->setCellValue('B' . $row, $datos['vehiculo']['codigo_unico']);
                $sheet->setCellValue('C' . $row, 'Placa:');
                $sheet->setCellValue('D' . $row, $datos['vehiculo']['numero_placa']);
                $sheet->setCellValue('E' . $row, 'Marca/Modelo:');
                $sheet->setCellValue('F' . $row, $datos['vehiculo']['marca'] . ' ' . $datos['vehiculo']['modelo']);
            }

            // ============== RESUMEN GLOBAL ==============
            $row += 2;
            $sheet->setCellValue('A' . $row, 'RESUMEN GLOBAL');
            $sheet->mergeCells('A' . $row . ':F' . $row);
            $sheet->getStyle('A' . $row)->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle('A' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FF9800');

            $row++;
            $sheet->setCellValue('A' . $row, 'Total Ingresos:');
            $sheet->setCellValue('B' . $row, '$' . number_format($datos['resumen_global']['total_ingresos'], 2));
            $sheet->setCellValue('C' . $row, 'Transacciones:');
            $sheet->setCellValue('D' . $row, $datos['resumen_global']['cantidad_transacciones']);
            $sheet->setCellValue('E' . $row, 'Promedio:');
            $sheet->setCellValue('F' . $row, '$' . number_format($datos['resumen_global']['promedio_ingreso'], 2));
            $sheet->getStyle('A' . $row . ':F' . $row)->getFont()->setBold(true);

            $row++;
            $sheet->setCellValue('A' . $row, '% Ingreso vs Inversión:');
            $sheet->setCellValue('B' . $row, $datos['inversion']['porcentaje_ingreso_vs_inversion'] . '%');
            $sheet->getStyle('A' . $row . ':B' . $row)->getFont()->setBold(true);

            // ============== TABLA DE CATEGORÍAS ==============
            $row += 2;
            $headerRow = $row;
            $headers = ['CATEGORÍA', 'TOTAL INGRESOS', 'TRANSACCIONES', 'PROMEDIO', '% DEL TOTAL'];
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $row, $header);
                $sheet->getStyle($col . $row)->getFont()->setBold(true);
                $sheet->getStyle($col . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('607D8B');
                $sheet->getStyle($col . $row)->getFont()->getColor()->setRGB('FFFFFF');
                $sheet->getStyle($col . $row)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                $col++;
            }

            // Datos de categorías
            $row++;
            $totalGlobal = $datos['resumen_global']['total_ingresos'];

            foreach ($datos['categorias'] as $categoria) {
                $porcentaje = $totalGlobal > 0 ? ($categoria['total_categoria'] / $totalGlobal) * 100 : 0;

                $sheet->setCellValue('A' . $row, $categoria['categoria_nombre']);
                $sheet->setCellValue('B' . $row, '$' . number_format($categoria['total_categoria'], 2));
                $sheet->setCellValue('C' . $row, $categoria['cantidad_transacciones']);
                $sheet->setCellValue('D' . $row, '$' . number_format($categoria['promedio_transaccion'], 2));
                $sheet->setCellValue('E' . $row, number_format($porcentaje, 2) . '%');

                $row++;
            }

            // Aplicar bordes a la tabla
            $lastRow = $row - 1;
            $sheet->getStyle('A' . $headerRow . ':E' . $lastRow)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

            // Autoajustar columnas
            foreach (range('A', 'F') as $col) {
                $sheet->getColumnDimension($col)->setAutoSize(true);
            }

            // Descargar archivo
            $negocioNombre = str_replace(' ', '_', $datos['negocio']->nombre);
            $vehiculoInfo = $datos['vehiculo'] ? '_Vehiculo_' . $datos['vehiculo']['numero_placa'] : '';
            $nombreArchivo = 'Ingresos_Categoria_Investor_' . $negocioNombre . $vehiculoInfo . '_' . $fechaInicial . '_a_' . $fechaFinal . '.xlsx';

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);

            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $nombreArchivo . '"');
            header('Cache-Control: max-age=0');

            $writer->save('php://output');
            exit;
        } catch (\Exception $e) {
            Log::error('Error exportando Excel: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Error al exportar a Excel',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * ============================================================================
     * MÉTODO PRIVADO PARA OBTENER DATOS PROCESADOS
     * ============================================================================
     */
    private function getProcessedIncomesByCategoryData(Request $request)
    {
        $user = Auth::user();
        $negocioId = $request->input('negocio_id');
        $vehicleId = $request->input('vehicle_id');
        $fechaInicial = $request->input('fecha_inicial');
        $fechaFinal = $request->input('fecha_final');

        $negocio = Business::find($negocioId);

        $vehiculo = null;
        if ($vehicleId) {
            $vehiculo = Vehicle::find($vehicleId);
        }

        // Query base
        $query = FinancialTransactions::where('tipo_de_transaccion', 'Ingreso')
            ->where('negocio_id', $negocioId)
            ->whereNull('caja_operativa_id')
            ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
            ->with(['categoria', 'vehicle']);

        if ($vehicleId) {
            $query->where('vehicle_id', $vehicleId);
        } else {
            $vehiculosConInversion = Investment::where('negocio_id', $negocioId)
                ->where('inversionista_id', $user->id)
                ->where('estado', 'ACTIVO')
                ->whereNotNull('vehicle_id')
                ->pluck('vehicle_id')
                ->toArray();

            if (!empty($vehiculosConInversion)) {
                $query->whereIn('vehicle_id', $vehiculosConInversion);
            }
        }

        $transacciones = $query->get();

        // Agrupar por categoría
        $categorias = $transacciones->groupBy('categoria_id')->map(function ($grupo, $categoriaId) {
            $categoria = $grupo->first()->categoria;
            $totalCategoria = $grupo->sum('importe_total');
            $cantidadTransacciones = $grupo->count();

            return [
                'categoria_id' => $categoriaId,
                'categoria_nombre' => $categoria ? $categoria->nombre : 'Sin categoría',
                'total_categoria' => $totalCategoria,
                'cantidad_transacciones' => $cantidadTransacciones,
                'promedio_transaccion' => $cantidadTransacciones > 0 ? $totalCategoria / $cantidadTransacciones : 0,
            ];
        })->values()->sortByDesc('total_categoria')->values();

        // Resumen global
        $totalIngresos = $transacciones->sum('importe_total');
        $cantidadTransacciones = $transacciones->count();

        $resumenGlobal = [
            'total_ingresos' => $totalIngresos,
            'cantidad_transacciones' => $cantidadTransacciones,
            'promedio_ingreso' => $cantidadTransacciones > 0 ? $totalIngresos / $cantidadTransacciones : 0,
        ];

        // Información de inversión
        $totalInvertido = Investment::where('negocio_id', $negocioId)
            ->where('inversionista_id', $user->id)
            ->where('estado', 'ACTIVO')
            ->when($vehicleId, function ($q) use ($vehicleId) {
                return $q->where('vehicle_id', $vehicleId);
            })
            ->sum('monto_invertido');

        $cantidadInversiones = Investment::where('negocio_id', $negocioId)
            ->where('inversionista_id', $user->id)
            ->where('estado', 'ACTIVO')
            ->when($vehicleId, function ($q) use ($vehicleId) {
                return $q->where('vehicle_id', $vehicleId);
            })
            ->count();

        $porcentajeIngresoVsInversion = $totalInvertido > 0
            ? round(($totalIngresos / $totalInvertido) * 100, 2)
            : 0;

        $inversionInfo = [
            'total_invertido' => $totalInvertido,
            'cantidad_inversiones' => $cantidadInversiones,
            'porcentaje_ingreso_vs_inversion' => $porcentajeIngresoVsInversion,
        ];

        // Información del vehículo
        $vehiculoInfo = null;
        if ($vehiculo) {
            $vehiculoInfo = [
                'id' => $vehiculo->id,
                'codigo_unico' => $vehiculo->codigo_unico ?? 'N/A',
                'numero_placa' => $vehiculo->numero_placa,
                'marca' => $vehiculo->marca,
                'modelo' => $vehiculo->modelo,
                'año' => $vehiculo->año,
            ];
        }

        return [
            'negocio' => $negocio,
            'vehiculo' => $vehiculoInfo,
            'inversion' => $inversionInfo,
            'resumen_global' => $resumenGlobal,
            'categorias' => $categorias,
        ];
    }
}
