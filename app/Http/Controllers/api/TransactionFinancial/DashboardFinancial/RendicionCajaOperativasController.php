<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use App\Models\FinancialTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\RendicionCajaOperativaExport;

class RendicionCajaOperativasController extends Controller
{
    public function resumenCajasOperativas(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->fecha_inicio;
            $fechaFin = $request->fecha_fin;

            // Obtener todas las cajas operativas activas
            $cajas = OperatingBox::where('estado', true)->get();

            $resultados = [];

            foreach ($cajas as $caja) {
                Log::info("🔄 Procesando caja: {$caja->nombre} (ID: {$caja->id})");

                // ==============================================================
                // CALCULAR INGRESOS DESDE TODAS LAS FUENTES
                // ==============================================================

                // 1. Ingresos desde FinancialTransactions (transacciones directas a la caja)
                $ingresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                // 2. Ingresos desde MovementsBox - USANDO EL SCOPE deCaja()
                $ingresosMovementsBox = MovementsBox::deCaja($caja->id)
                    ->entreFechas($fechaInicio, $fechaFin)
                    ->where('tipo', 'ingreso')
                    ->sum('monto');

                // Total de ingresos = suma de ambas fuentes
                $totalIngresos = $ingresosFinancialTransactions + $ingresosMovementsBox;

                Log::info("📊 Ingresos de caja {$caja->nombre}", [
                    'ingresosFinancialTransactions' => $ingresosFinancialTransactions,
                    'ingresosMovementsBox' => $ingresosMovementsBox,
                    'totalIngresos' => $totalIngresos
                ]);

                // ==============================================================
                // CALCULAR EGRESOS
                // ==============================================================

                // Egresos desde FinancialTransactions
                $egresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                // Egresos desde MovementsBox - USANDO EL SCOPE deCaja()
                $egresosMovementsBox = MovementsBox::deCaja($caja->id)
                    ->entreFechas($fechaInicio, $fechaFin)
                    ->where('tipo', 'egreso')
                    ->sum('monto');

                // Total de egresos
                $totalEgresos = abs($egresosFinancialTransactions) + abs($egresosMovementsBox);

                Log::info("📊 Egresos de caja {$caja->nombre}", [
                    'egresosFinancialTransactions' => $egresosFinancialTransactions,
                    'egresosMovementsBox' => $egresosMovementsBox,
                    'totalEgresos' => $totalEgresos
                ]);

                // ==============================================================
                // CALCULAR SALDO FINAL
                // ==============================================================
                $saldoFinal = $totalIngresos - $totalEgresos;

                // ==============================================================
                // OBTENER DETALLE DE INGRESOS
                // ==============================================================

                // Ingresos desde FinancialTransactions
                $ingresosTransacciones = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->with(['categoria', 'user.generalData', 'vehicle'])
                    ->get()
                    ->map(function ($transaccion) {
                        return [
                            'id' => $transaccion->id,
                            'tipo_fuente' => 'Transacción Financiera',
                            'item' => $transaccion->item,
                            'fecha' => $transaccion->fecha,
                            'monto' => floatval($transaccion->importe_total),
                            'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                            'vehiculo' => $transaccion->vehicle ?
                                "{$transaccion->vehicle->codigo_unico} - {$transaccion->vehicle->numero_placa}" :
                                'Sin vehículo',
                            'observaciones' => $transaccion->observaciones ?? '',
                            'usuario' => $transaccion->user && $transaccion->user->generalData ?
                                "{$transaccion->user->generalData->nombre} {$transaccion->user->generalData->apellido}" :
                                'N/A'
                        ];
                    });

                // Ingresos desde MovementsBox - USANDO EL SCOPE deCaja()
                $ingresosMovimientos = MovementsBox::deCaja($caja->id)
                    ->entreFechas($fechaInicio, $fechaFin)
                    ->where('tipo', 'ingreso')
                    ->with(['transaccionFinanciera'])
                    ->get()
                    ->map(function ($movimiento) {
                        return [
                            'id' => $movimiento->id,
                            'tipo_fuente' => 'Movimiento de Caja',
                            'item' => $movimiento->descripcion,
                            'fecha' => $movimiento->fecha_movimiento,
                            'monto' => floatval($movimiento->monto),
                            'categoria' => 'Movimiento Manual',
                            'vehiculo' => 'N/A',
                            'observaciones' => $movimiento->descripcion ?? '',
                            'usuario' => 'Sistema'
                        ];
                    });

                $detalleIngresos = $ingresosTransacciones->merge($ingresosMovimientos)->sortByDesc('fecha')->values();

                // ==============================================================
                // OBTENER DETALLE DE EGRESOS
                // ==============================================================

                // Egresos desde FinancialTransactions
                $egresosTransacciones = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->with(['categoria', 'user.generalData', 'vehicle'])
                    ->get()
                    ->map(function ($transaccion) {
                        return [
                            'id' => $transaccion->id,
                            'tipo_fuente' => 'Transacción Financiera',
                            'item' => $transaccion->item,
                            'fecha' => $transaccion->fecha,
                            'monto' => floatval($transaccion->importe_total),
                            'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                            'vehiculo' => $transaccion->vehicle ?
                                "{$transaccion->vehicle->codigo_unico} - {$transaccion->vehicle->numero_placa}" :
                                'Sin vehículo',
                            'observaciones' => $transaccion->observaciones ?? '',
                            'usuario' => $transaccion->user && $transaccion->user->generalData ?
                                "{$transaccion->user->generalData->nombre} {$transaccion->user->generalData->apellido}" :
                                'N/A'
                        ];
                    });

                // Egresos desde MovementsBox - USANDO EL SCOPE deCaja()
                $egresosMovimientos = MovementsBox::deCaja($caja->id)
                    ->entreFechas($fechaInicio, $fechaFin)
                    ->where('tipo', 'egreso')
                    ->with(['transaccionFinanciera'])
                    ->get()
                    ->map(function ($movimiento) {
                        return [
                            'id' => $movimiento->id,
                            'tipo_fuente' => 'Movimiento de Caja',
                            'item' => $movimiento->descripcion,
                            'fecha' => $movimiento->fecha_movimiento,
                            'monto' => abs(floatval($movimiento->monto)),
                            'categoria' => 'Movimiento Manual',
                            'vehiculo' => 'N/A',
                            'observaciones' => $movimiento->descripcion ?? '',
                            'usuario' => 'Sistema'
                        ];
                    });

                $detalleEgresos = $egresosTransacciones->merge($egresosMovimientos)->sortByDesc('fecha')->values();

                // ==============================================================
                // CONSTRUIR RESULTADO
                // ==============================================================
                $resultados[] = [
                    'caja' => [
                        'id' => $caja->id,
                        'nombre' => $caja->nombre,
                        'saldo_actual' => floatval($caja->saldo),
                        'descripcion' => $caja->descripcion ?? ''
                    ],
                    'periodo' => [
                        'fecha_inicio' => $fechaInicio,
                        'fecha_fin' => $fechaFin,
                    ],
                    'resumen' => [
                        'total_ingresos' => floatval($totalIngresos),
                        'total_ingresos_formatted' => number_format($totalIngresos, 2, '.', ','),
                        'total_egresos' => floatval($totalEgresos),
                        'total_egresos_formatted' => number_format($totalEgresos, 2, '.', ','),
                        'saldo_final' => floatval($saldoFinal),
                        'saldo_final_formatted' => number_format($saldoFinal, 2, '.', ','),
                        'cantidad_ingresos' => $detalleIngresos->count(),
                        'cantidad_egresos' => $detalleEgresos->count(),
                    ],
                    'detalle_ingresos' => $detalleIngresos,
                    'detalle_egresos' => $detalleEgresos
                ];
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Resumen de cajas operativas obtenido correctamente',
                'data' => $resultados,
                'timestamp' => now()->toDateTimeString()
            ]);
        } catch (\Exception $e) {
            Log::error('💥 Error al obtener resumen de cajas operativas', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el resumen de cajas operativas',
                'error' => $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]
            ], 500);
        }
    }

    public function exportarExcel(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->fecha_inicio;
            $fechaFin = $request->fecha_fin;

            // Obtener datos para exportar
            $datos = $this->obtenerDatosParaExportar($fechaInicio, $fechaFin);

            // Generar nombre de archivo
            $nombreArchivo = 'Rendicion_Cajas_Operativas_' . $fechaInicio . '_al_' . $fechaFin . '.xlsx';

            // Exportar a Excel
            return Excel::download(
                new RendicionCajaOperativaExport($datos),
                $nombreArchivo
            );
        } catch (\Exception $e) {
            Log::error('Error al exportar Excel', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el archivo Excel',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function exportarPDF(Request $request)
    {
        try {
            $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->fecha_inicio;
            $fechaFin = $request->fecha_fin;

            // Obtener datos para exportar
            $datos = $this->obtenerDatosParaExportar($fechaInicio, $fechaFin);

            // Generar nombre de archivo
            $nombreArchivo = 'Rendicion_Cajas_Operativas_' . $fechaInicio . '_al_' . $fechaFin . '.pdf';

            // Generar PDF
            $pdf = PDF::loadView('pdf.rendicion_cajas_operativas', [
                'datos' => $datos,
                'fechaInicio' => $fechaInicio,
                'fechaFin' => $fechaFin
            ]);

            // Opciones para el PDF
            $pdf->setPaper('a4', 'landscape');
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true
            ]);

            // Descargar PDF
            return $pdf->download($nombreArchivo);
        } catch (\Exception $e) {
            Log::error('Error al exportar PDF', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al generar el archivo PDF',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Método privado para obtener datos para exportar
     */
    private function obtenerDatosParaExportar($fechaInicio, $fechaFin)
    {
        // Obtener todas las cajas operativas
        $cajas = OperatingBox::where('estado', true)->get();

        $resultados = [];

        foreach ($cajas as $caja) {
            // Ingresos desde FinancialTransactions
            $ingresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->sum('importe_total');

            // Ingresos desde MovementsBox - USANDO EL SCOPE deCaja()
            $ingresosMovementsBox = MovementsBox::deCaja($caja->id)
                ->entreFechas($fechaInicio, $fechaFin)
                ->where('tipo', 'ingreso')
                ->sum('monto');

            $totalIngresos = $ingresosFinancialTransactions + $ingresosMovementsBox;

            // Egresos desde FinancialTransactions
            $egresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->sum('importe_total');

            // Egresos desde MovementsBox - USANDO EL SCOPE deCaja()
            $egresosMovementsBox = MovementsBox::deCaja($caja->id)
                ->entreFechas($fechaInicio, $fechaFin)
                ->where('tipo', 'egreso')
                ->sum('monto');

            $totalEgresos = abs($egresosFinancialTransactions) + abs($egresosMovementsBox);

            $saldoFinal = $totalIngresos - $totalEgresos;

            // Obtener detalles de ingresos para el reporte
            $ingresos = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->with(['categoria', 'vehicle', 'user.generalData'])
                ->get()
                ->map(function ($transaccion) {
                    return [
                        'id' => $transaccion->id,
                        'item' => $transaccion->item,
                        'fecha' => $transaccion->fecha,
                        'importe_total' => $transaccion->importe_total,
                        'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                        'vehiculo' => $transaccion->vehicle ?
                            "{$transaccion->vehicle->codigo_unico} - {$transaccion->vehicle->numero_placa}" :
                            'Sin vehículo',
                        'observaciones' => $transaccion->observaciones ?? ''
                    ];
                });

            // Obtener detalles de egresos para el reporte
            $egresos = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->with(['categoria', 'vehicle', 'user.generalData'])
                ->get()
                ->map(function ($transaccion) {
                    return [
                        'id' => $transaccion->id,
                        'item' => $transaccion->item,
                        'fecha' => $transaccion->fecha,
                        'importe_total' => $transaccion->importe_total,
                        'categoria' => $transaccion->categoria->nombre ?? 'Sin categoría',
                        'vehiculo' => $transaccion->vehicle ?
                            "{$transaccion->vehicle->codigo_unico} - {$transaccion->vehicle->numero_placa}" :
                            'Sin vehículo',
                        'observaciones' => $transaccion->observaciones ?? ''
                    ];
                });

            $resultados[] = [
                'caja' => [
                    'id' => $caja->id,
                    'nombre' => $caja->nombre,
                    'saldo_actual' => $caja->saldo,
                ],
                'periodo' => [
                    'fecha_inicio' => $fechaInicio,
                    'fecha_fin' => $fechaFin,
                ],
                'resumen' => [
                    'total_ingresos' => $totalIngresos,
                    'total_egresos' => $totalEgresos,
                    'saldo_final' => $saldoFinal,
                ],
                'ingresos' => $ingresos,
                'egresos' => $egresos
            ];
        }

        return $resultados;
    }
}
