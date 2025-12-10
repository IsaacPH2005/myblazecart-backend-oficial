<?php

namespace App\Http\Controllers\api\TransactionFinancial;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\FinancialTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class FinancialReportController extends Controller
{

    /**
     * Obtener estado financiero de todos los negocios en un rango de fechas
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getAllBusinessesFinancialStatement(Request $request)
    {
        try {
            // Validar parámetros
            $validator = Validator::make($request->all(), [
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

            $fechaInicial = $request->input('fecha_inicial');
            $fechaFinal = $request->input('fecha_final');

            // Obtener todos los negocios
            $businesses = Business::all();

            // Array para almacenar los resultados por negocio
            $businessesFinancialData = [];

            // Variables para los totales generales
            $totalGeneralIngresos = 0;
            $totalGeneralEgresos = 0;
            $totalGeneralMargen = 0;

            foreach ($businesses as $business) {
                // Calcular totales de ingresos para el negocio (EXCLUIR ingresos de caja operativa)
                $totalIngresos = FinancialTransactions::where('negocio_id', $business->id)
                    ->where('tipo_de_transaccion', 'Ingreso')
                    ->whereNull('caja_operativa_id') // EXCLUIR ingresos de caja operativa (recargas internas)
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                // Calcular totales de egresos para el negocio (TODOS los egresos)
                $totalEgresos = FinancialTransactions::where('negocio_id', $business->id)
                    ->where('tipo_de_transaccion', 'Egreso')
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->sum('importe_total');

                // Calcular margen bruto
                $margenBruto = $totalIngresos - $totalEgresos;

                // Calcular rentabilidad
                $rentabilidad = $totalIngresos > 0 ? ($margenBruto / $totalIngresos) * 100 : 0;

                // Obtener transacciones por estado (con regla global: ingresos sin caja + todos egresos)
                $transaccionesPorEstado = FinancialTransactions::where('negocio_id', $business->id)
                    ->where(function ($query) {
                        // Todos los egresos
                        $query->where('tipo_de_transaccion', 'Egreso')
                            // O ingresos sin caja operativa
                            ->orWhere(function ($query) {
                                $query->where('tipo_de_transaccion', 'Ingreso')
                                    ->whereNull('caja_operativa_id');
                            });
                    })
                    ->whereBetween('fecha', [$fechaInicial, $fechaFinal])
                    ->join('transaction_states', 'financial_transactions.estado_de_transaccion_id', '=', 'transaction_states.id')
                    ->select(
                        'transaction_states.id as estado_id',
                        'transaction_states.nombre as estado_nombre',
                        'financial_transactions.tipo_de_transaccion',
                        DB::raw('COUNT(*) as total_transacciones'),
                        DB::raw('SUM(importe_total) as total_importe')
                    )
                    ->groupBy('transaction_states.id', 'transaction_states.nombre', 'financial_transactions.tipo_de_transaccion')
                    ->get();

                // Organizar datos por estado
                $estadosFinancieros = [];
                foreach ($transaccionesPorEstado as $transaccion) {
                    $estadoId = $transaccion->estado_id;
                    $estadoNombre = $transaccion->estado_nombre;
                    $tipo = $transaccion->tipo_de_transaccion;

                    if (!isset($estadosFinancieros[$estadoId])) {
                        $estadosFinancieros[$estadoId] = [
                            'estado_id' => $estadoId,
                            'estado_nombre' => $estadoNombre,
                            'ingresos' => 0,
                            'egresos' => 0,
                            'total_transacciones_ingresos' => 0,
                            'total_transacciones_egresos' => 0,
                            'balance_estado' => 0
                        ];
                    }

                    if ($tipo === 'Ingreso') {
                        $estadosFinancieros[$estadoId]['ingresos'] = floatval($transaccion->total_importe);
                        $estadosFinancieros[$estadoId]['total_transacciones_ingresos'] = intval($transaccion->total_transacciones);
                    } else {
                        $estadosFinancieros[$estadoId]['egresos'] = floatval($transaccion->total_importe);
                        $estadosFinancieros[$estadoId]['total_transacciones_egresos'] = intval($transaccion->total_transacciones);
                    }

                    $estadosFinancieros[$estadoId]['balance_estado'] =
                        $estadosFinancieros[$estadoId]['ingresos'] - $estadosFinancieros[$estadoId]['egresos'];
                }

                // Agregar datos del negocio al array de resultados
                $businessesFinancialData[] = [
                    'negocio' => [
                        'id' => $business->id,
                        'nombre' => $business->nombre,
                        'estado' => $business->estado ? 'Activo' : 'Inactivo'
                    ],
                    'resumen_financiero' => [
                        'total_ingresos' => number_format($totalIngresos, 2),
                        'total_egresos' => number_format($totalEgresos, 2),
                        'margen_bruto' => number_format($margenBruto, 2),
                        'rentabilidad_porcentaje' => number_format($rentabilidad, 2) . '%'
                    ],
                    'detalle_por_estado' => array_values($estadosFinancieros)
                ];

                // Acumular totales generales
                $totalGeneralIngresos += $totalIngresos;
                $totalGeneralEgresos += $totalEgresos;
            }

            // Calcular margen general
            $totalGeneralMargen = $totalGeneralIngresos - $totalGeneralEgresos;
            $rentabilidadGeneral = $totalGeneralIngresos > 0 ? ($totalGeneralMargen / $totalGeneralIngresos) * 100 : 0;

            // Preparar respuesta
            $response = [
                'status' => 'success',
                'message' => 'Estado financiero de todos los negocios obtenido correctamente',
                'data' => [
                    'periodo' => [
                        'fecha_inicial' => $fechaInicial,
                        'fecha_final' => $fechaFinal,
                        'dias_periodo' => \Carbon\Carbon::parse($fechaInicial)->diffInDays(\Carbon\Carbon::parse($fechaFinal)) + 1
                    ],
                    'resumen_general' => [
                        'total_ingresos' => number_format($totalGeneralIngresos, 2),
                        'total_egresos' => number_format($totalGeneralEgresos, 2),
                        'margen_bruto' => number_format($totalGeneralMargen, 2),
                        'rentabilidad_porcentaje' => number_format($rentabilidadGeneral, 2) . '%',
                        'cantidad_negocios' => $businesses->count()
                    ],
                    'negocios' => $businessesFinancialData
                ],
                'timestamp' => now()->toDateTimeString()
            ];

            return response()->json($response, 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el estado financiero de todos los negocios',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
