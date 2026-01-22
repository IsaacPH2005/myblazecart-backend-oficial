<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use App\Models\OperatingBoxHistorie;
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

            $cajas = OperatingBox::where('estado', true)->get();

            $resultados = [];

            foreach ($cajas as $caja) {
                Log::info("🔄 Procesando caja: {$caja->nombre} (ID: {$caja->id})");

                // ==============================================================
                // CALCULAR SALDO INICIAL (antes del período)
                // ==============================================================

                // Ingresos antes del período desde FinancialTransactions
                $ingresosAntesFinancial = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->where('fecha', '<', $fechaInicio)
                    ->sum('importe_total');

                // Ingresos antes del período desde OperatingBoxHistorie
                $ingresosAntesHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'ingreso')
                    ->where('created_at', '<', $fechaInicio . ' 00:00:00')
                    ->sum('monto');

                // Egresos antes del período desde FinancialTransactions
                $egresosAntesFinancial = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->where('fecha', '<', $fechaInicio)
                    ->sum('importe_total');

                // Egresos antes del período desde OperatingBoxHistorie
                $egresosAntesHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'egreso')
                    ->where('created_at', '<', $fechaInicio . ' 00:00:00')
                    ->sum('monto');

                $saldoInicial = ($ingresosAntesFinancial + $ingresosAntesHistorial) -
                    (abs($egresosAntesFinancial) + abs($egresosAntesHistorial));

                Log::info("💰 Saldo inicial de caja {$caja->nombre}", [
                    'ingresosAntesFinancial' => $ingresosAntesFinancial,
                    'ingresosAntesHistorial' => $ingresosAntesHistorial,
                    'egresosAntesFinancial' => $egresosAntesFinancial,
                    'egresosAntesHistorial' => $egresosAntesHistorial,
                    'saldoInicial' => $saldoInicial
                ]);

                // ==============================================================
                // CALCULAR INGRESOS DEL PERÍODO
                // ==============================================================

                $ingresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $ingresosHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'ingreso')
                    ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                    ->sum('monto');

                $totalIngresosPeriodo = $ingresosFinancialTransactions + $ingresosHistorial;

                Log::info("📊 Ingresos del período de caja {$caja->nombre}", [
                    'ingresosFinancialTransactions' => $ingresosFinancialTransactions,
                    'ingresosHistorial' => $ingresosHistorial,
                    'totalIngresosPeriodo' => $totalIngresosPeriodo
                ]);

                // ==============================================================
                // CALCULAR EGRESOS DEL PERÍODO
                // ==============================================================

                $egresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('importe_total');

                $egresosHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'egreso')
                    ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                    ->sum('monto');

                $totalEgresosPeriodo = abs($egresosFinancialTransactions) + abs($egresosHistorial);

                Log::info("📊 Egresos del período de caja {$caja->nombre}", [
                    'egresosFinancialTransactions' => $egresosFinancialTransactions,
                    'egresosHistorial' => $egresosHistorial,
                    'totalEgresosPeriodo' => $totalEgresosPeriodo
                ]);

                // ==============================================================
                // CALCULAR SALDO FINAL
                // ==============================================================
                $saldoFinal = $saldoInicial + $totalIngresosPeriodo - $totalEgresosPeriodo;

                Log::info("💵 Resumen de caja {$caja->nombre}", [
                    'saldoInicial' => $saldoInicial,
                    'totalIngresosPeriodo' => $totalIngresosPeriodo,
                    'totalEgresosPeriodo' => $totalEgresosPeriodo,
                    'saldoFinal' => $saldoFinal,
                    'saldoActualDB' => $caja->saldo
                ]);

                // ==============================================================
                // OBTENER DETALLE DE INGRESOS
                // ==============================================================

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

                $ingresosHistorialDetalle = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'ingreso')
                    ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                    ->with(['financialTransaction'])
                    ->get()
                    ->map(function ($historial) {
                        return [
                            'id' => $historial->id,
                            'tipo_fuente' => 'Historial Caja Operativa',
                            'item' => $historial->descripcion ?? 'Recarga de caja',
                            'fecha' => $historial->created_at->format('Y-m-d'),
                            'monto' => floatval($historial->monto),
                            'categoria' => 'Movimiento de Caja',
                            'vehiculo' => 'N/A',
                            'observaciones' => $historial->descripcion ?? '',
                            'usuario' => 'Sistema'
                        ];
                    });

                $detalleIngresos = $ingresosTransacciones->merge($ingresosHistorialDetalle)->sortByDesc('fecha')->values();

                // ==============================================================
                // OBTENER DETALLE DE EGRESOS
                // ==============================================================

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
                            'monto' => floatval(abs($transaccion->importe_total)),
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

                $egresosHistorialDetalle = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                    ->where('tipo_movimiento', 'egreso')
                    ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                    ->with(['financialTransaction'])
                    ->get()
                    ->map(function ($historial) {
                        return [
                            'id' => $historial->id,
                            'tipo_fuente' => 'Historial Caja Operativa',
                            'item' => $historial->descripcion ?? 'Retiro de caja',
                            'fecha' => $historial->created_at->format('Y-m-d'),
                            'monto' => floatval(abs($historial->monto)),
                            'categoria' => 'Movimiento de Caja',
                            'vehiculo' => 'N/A',
                            'observaciones' => $historial->descripcion ?? '',
                            'usuario' => 'Sistema'
                        ];
                    });

                $detalleEgresos = $egresosTransacciones->merge($egresosHistorialDetalle)->sortByDesc('fecha')->values();

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
                        'saldo_inicial' => floatval($saldoInicial),
                        'saldo_inicial_formatted' => number_format($saldoInicial, 2, '.', ','),
                        'total_ingresos' => floatval($totalIngresosPeriodo),
                        'total_ingresos_formatted' => number_format($totalIngresosPeriodo, 2, '.', ','),
                        'total_egresos' => floatval($totalEgresosPeriodo),
                        'total_egresos_formatted' => number_format($totalEgresosPeriodo, 2, '.', ','),
                        'saldo_final' => floatval($saldoFinal),
                        'saldo_final_formatted' => number_format($saldoFinal, 2, '.', ','),
                        'cantidad_ingresos' => $detalleIngresos->count(),
                        'cantidad_egresos' => $detalleEgresos->count(),
                    ],
                    'detalle_ingresos' => $detalleIngresos,
                    'detalle_egresos' => $detalleEgresos,
                    'fuentes_ingresos' => [
                        'financial_transactions' => floatval($ingresosFinancialTransactions),
                        'historial_caja' => floatval($ingresosHistorial)
                    ],
                    'fuentes_egresos' => [
                        'financial_transactions' => floatval(abs($egresosFinancialTransactions)),
                        'historial_caja' => floatval(abs($egresosHistorial))
                    ]
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

            $datos = $this->obtenerDatosParaExportar($fechaInicio, $fechaFin);

            $nombreArchivo = 'Rendicion_Cajas_Operativas_' . $fechaInicio . '_al_' . $fechaFin . '.xlsx';

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

            $datos = $this->obtenerDatosParaExportar($fechaInicio, $fechaFin);

            $nombreArchivo = 'Rendicion_Cajas_Operativas_' . $fechaInicio . '_al_' . $fechaFin . '.pdf';

            $pdf = PDF::loadView('pdf.rendicion_cajas_operativas', [
                'datos' => $datos,
                'fechaInicio' => $fechaInicio,
                'fechaFin' => $fechaFin
            ]);

            $pdf->setPaper('a4', 'landscape');
            $pdf->setOptions([
                'isHtml5ParserEnabled' => true,
                'isRemoteEnabled' => true
            ]);

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

    private function obtenerDatosParaExportar($fechaInicio, $fechaFin)
    {
        $cajas = OperatingBox::where('estado', true)->get();
        $resultados = [];

        foreach ($cajas as $caja) {
            // Saldo inicial
            $ingresosAntesFinancial = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->where('fecha', '<', $fechaInicio)
                ->sum('importe_total');

            $ingresosAntesHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                ->where('tipo_movimiento', 'ingreso')
                ->where('created_at', '<', $fechaInicio . ' 00:00:00')
                ->sum('monto');

            $egresosAntesFinancial = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->where('fecha', '<', $fechaInicio)
                ->sum('importe_total');

            $egresosAntesHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                ->where('tipo_movimiento', 'egreso')
                ->where('created_at', '<', $fechaInicio . ' 00:00:00')
                ->sum('monto');

            $saldoInicial = ($ingresosAntesFinancial + $ingresosAntesHistorial) -
                (abs($egresosAntesFinancial) + abs($egresosAntesHistorial));

            // Ingresos y egresos del período
            $ingresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Ingreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->sum('importe_total');

            $ingresosHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                ->where('tipo_movimiento', 'ingreso')
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('monto');

            $totalIngresos = $ingresosFinancialTransactions + $ingresosHistorial;

            $egresosFinancialTransactions = FinancialTransactions::where('caja_operativa_id', $caja->id)
                ->where('tipo_de_transaccion', 'Egreso')
                ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                ->sum('importe_total');

            $egresosHistorial = OperatingBoxHistorie::where('operating_box_id', $caja->id)
                ->where('tipo_movimiento', 'egreso')
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('monto');

            $totalEgresos = abs($egresosFinancialTransactions) + abs($egresosHistorial);

            $saldoFinal = $saldoInicial + $totalIngresos - $totalEgresos;

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
                    'saldo_inicial' => $saldoInicial,
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
