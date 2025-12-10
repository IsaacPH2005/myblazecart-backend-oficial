<?php

namespace App\Http\Controllers\api\TransactionFinancial\DashboardFinancial;

use App\Http\Controllers\Controller;
use App\Models\FinancialTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NegocioConMayorEgresosController extends Controller
{
    /**
     * Obtener el negocio con mayor egreso (histórico)
     *
     * @param Request $request
     */
    public function getBusinessWithHighestExpense(Request $request)
    {
        try {
            // Obtener el negocio con mayor egreso histórico
            $negocioMayor = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->join('businesses', 'financial_transactions.negocio_id', '=', 'businesses.id')
                ->select(
                    'businesses.id',
                    'businesses.nombre',
                    DB::raw('SUM(financial_transactions.importe_total) as total_egresos'),
                    DB::raw('COUNT(financial_transactions.id) as cantidad_transacciones')
                )
                ->groupBy('businesses.id', 'businesses.nombre')
                ->orderBy('total_egresos', 'desc')
                ->first();

            if (!$negocioMayor) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'No se encontraron egresos registrados',
                    'data' => null
                ], 200);
            }

            // Obtener desglose por categorías para este negocio
            $desgloseCategorias = FinancialTransactions::where('tipo_de_transaccion', 'Egreso')
                ->where('negocio_id', $negocioMayor->id)
                ->join('categories', 'financial_transactions.categoria_id', '=', 'categories.id')
                ->select(
                    'categories.id',
                    'categories.nombre',
                    DB::raw('SUM(financial_transactions.importe_total) as total_categoria'),
                    DB::raw('COUNT(financial_transactions.id) as cantidad_transacciones')
                )
                ->groupBy('categories.id', 'categories.nombre')
                ->orderBy('total_categoria', 'desc')
                ->get();

            // Guardar el total de egresos para usarlo en el cálculo de porcentajes
            $totalEgresosNegocio = $negocioMayor->total_egresos;

            return response()->json([
                'status' => 'success',
                'message' => 'Negocio con mayor egreso obtenido correctamente',
                'data' => [
                    'negocio' => [
                        'id' => $negocioMayor->id,
                        'nombre' => $negocioMayor->nombre,
                        'total_egresos' => number_format($negocioMayor->total_egresos, 2),
                        'cantidad_transacciones' => $negocioMayor->cantidad_transacciones
                    ],
                    'desglose_categorias' => $desgloseCategorias->map(function ($categoria) use ($totalEgresosNegocio) {
                        return [
                            'id' => $categoria->id,
                            'nombre' => $categoria->nombre,
                            'total_categoria' => number_format($categoria->total_categoria, 2),
                            'cantidad_transacciones' => $categoria->cantidad_transacciones,
                            'porcentaje' => $totalEgresosNegocio > 0
                                ? number_format(($categoria->total_categoria / $totalEgresosNegocio) * 100, 2) . '%'
                                : '0%'
                        ];
                    })
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el negocio con mayor egreso',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
