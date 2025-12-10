<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\MovementsBox;
use App\Models\OperatingBox;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\RendicionCajaOperativaExport;

class RendicionCajaOperativasController extends Controller
{
    public function resumenCajasOperativas(Request $request)
    {
        $request->validate([
            'fecha_inicio' => 'required|date',
            'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
        ]);

        $fechaInicio = $request->fecha_inicio;
        $fechaFin = $request->fecha_fin;

        // Obtener todas las cajas operativas
        $cajas = OperatingBox::where('estado', true)->get();

        $resultados = [];

        foreach ($cajas as $caja) {
            // Obtener movimientos en el rango de fechas
            $movimientos = MovementsBox::deCaja($caja->id)
                ->entreFechas($fechaInicio, $fechaFin)
                ->get();

            // Calcular totales
            $totalIngresos = $movimientos->where('tipo', 'ingreso')->sum('monto');
            $totalEgresos = $movimientos->where('tipo', 'egreso')->sum('monto');

            // Calcular saldo final (ingresos - egresos)
            $saldoFinal = $totalIngresos - $totalEgresos;

            // Obtener detalles de egresos
            $egresos = MovementsBox::whereHas('transaccionFinanciera', function ($query) use ($caja) {
                $query->where('caja_operativa_id', $caja->id);
            })
                ->whereBetween('fecha_movimiento', [$fechaInicio, $fechaFin])
                ->where('tipo', 'egreso')
                ->with(['transaccionFinanciera'])
                ->get()
                ->map(function ($movimiento) {
                    $transaccion = $movimiento->transaccionFinanciera;
                    return [
                        'id' => $transaccion ? $transaccion->id : null,
                        'item' => $transaccion ? $transaccion->item : null,
                        'fecha' => $transaccion ? $transaccion->fecha : null,
                        'importe_total' => $transaccion ? $transaccion->importe_total : null,
                        'observaciones' => $transaccion ? $transaccion->observaciones : null,
                        'monto' => $movimiento->monto,
                        'descripcion_movimiento' => $movimiento->descripcion,
                        'fecha_movimiento' => $movimiento->fecha_movimiento
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
                'egresos' => $egresos
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $resultados
        ]);
    }

    public function exportarExcel(Request $request)
    {
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
    }

    public function exportarPDF(Request $request)
    {
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
    }

    private function obtenerDatosParaExportar($fechaInicio, $fechaFin)
    {
        // Obtener todas las cajas operativas
        $cajas = OperatingBox::where('estado', true)->get();

        $resultados = [];

        foreach ($cajas as $caja) {
            // Obtener movimientos en el rango de fechas
            $movimientos = MovementsBox::deCaja($caja->id)
                ->entreFechas($fechaInicio, $fechaFin)
                ->get();

            // Calcular totales
            $totalIngresos = $movimientos->where('tipo', 'ingreso')->sum('monto');
            $totalEgresos = $movimientos->where('tipo', 'egreso')->sum('monto');

            // Calcular saldo final (ingresos - egresos)
            $saldoFinal = $totalIngresos - $totalEgresos;

            // Obtener detalles de egresos
            $egresos = MovementsBox::whereHas('transaccionFinanciera', function ($query) use ($caja) {
                $query->where('caja_operativa_id', $caja->id);
            })
                ->whereBetween('fecha_movimiento', [$fechaInicio, $fechaFin])
                ->where('tipo', 'egreso')
                ->with(['transaccionFinanciera'])
                ->get()
                ->map(function ($movimiento) {
                    $transaccion = $movimiento->transaccionFinanciera;
                    return [
                        'id' => $transaccion ? $transaccion->id : null,
                        'item' => $transaccion ? $transaccion->item : null,
                        'fecha' => $transaccion ? $transaccion->fecha : null,
                        'importe_total' => $transaccion ? $transaccion->importe_total : null,
                        'observaciones' => $transaccion ? $transaccion->observaciones : null,
                        'monto' => $movimiento->monto,
                        'descripcion_movimiento' => $movimiento->descripcion,
                        'fecha_movimiento' => $movimiento->fecha_movimiento
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
                'egresos' => $egresos
            ];
        }

        return $resultados;
    }
}
