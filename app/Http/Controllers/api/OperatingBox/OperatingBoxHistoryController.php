<?php

namespace App\Http\Controllers\api\OperatingBox;

use App\Http\Controllers\Controller;
use App\Services\OperatingBoxHistoryService;
use App\Models\OperatingBoxHistorie;
use App\Models\FinancialTransactions;
use App\Models\TransactionStates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperatingBoxHistoryController extends Controller
{
    protected $operatingBoxHistoryService;

    public function __construct(OperatingBoxHistoryService $operatingBoxHistoryService)
    {
        $this->operatingBoxHistoryService = $operatingBoxHistoryService;
    }

    /**
     * Obtener el historial de una caja operativa
     *
     * @param Request $request
     * @param int $id
     */
    public function getHistory(Request $request, $id): JsonResponse
    {
        try {
            $filters = $request->only([
                'fecha_desde',
                'fecha_hasta',
                'tipo_movimiento',
                'per_page'
            ]);

            // Asegurarse de que per_page sea un entero
            if (isset($filters['per_page'])) {
                $filters['per_page'] = (int)$filters['per_page'];
            }

            $data = $this->operatingBoxHistoryService->obtenerHistorial($id, $filters);
            return response()->json([
                'status' => 'success',
                'message' => 'Historial de caja operativa obtenido correctamente',
                'data' => $data,
                'timestamp' => now()->toDateTimeString()
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al obtener el historial de la caja operativa',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cambiar estado de transacción de reembolso a pagado
     *
     * @param Request $request
     * @param int $historyId
     * @return JsonResponse
     */
    public function payRefundTransaction(Request $request, $historyId): JsonResponse
    {
        try {
            DB::beginTransaction();

            // Obtener el registro del historial
            $history = OperatingBoxHistorie::with(['operatingBox', 'financialTransaction'])
                ->findOrFail($historyId);

            // Verificar que es un reembolso y tiene una transacción financiera asociada
            if ($history->tipo_movimiento !== 'reembolso' || !$history->financial_transaction_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Este movimiento no es un reembolso o no tiene una transacción financiera asociada'
                ], 422);
            }

            // Obtener la transacción financiera
            $transaction = $history->financialTransaction;

            // Verificar que el estado actual es "Reembolso" (id=1)
            if ($transaction->estado_de_transaccion_id != 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'La transacción no está en estado de reembolso'
                ], 422);
            }

            $operatingBox = $history->operatingBox;
            $monto = $history->monto; // Este es el monto original de la transacción

            // Verificar que la caja operativa tiene saldo suficiente
            if ($operatingBox->saldo < $monto) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Saldo insuficiente en la caja operativa',
                    'saldo_disponible' => $operatingBox->saldo,
                    'monto_requerido' => $monto
                ], 422);
            }

            // Guardar el saldo anterior antes de modificarlo
            $saldoAnterior = $operatingBox->saldo;

            // Actualizar saldo de la caja operativa
            $operatingBox->saldo -= $monto;
            $operatingBox->save();

            // Cambiar el estado de la transacción a "Pagado" (id=2)
            $transaction->estado_de_transaccion_id = 2;
            $transaction->save();

            // Registrar un nuevo movimiento en el historial: egreso
            $this->operatingBoxHistoryService->registrarMovimiento(
                $operatingBox,
                $monto,
                'egreso',
                'Pago de reembolso: ' . $transaction->item,
                $transaction,
                $saldoAnterior, // Saldo antes del movimiento
                $operatingBox->saldo // Saldo después del movimiento
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Reembolso pagado correctamente',
                'data' => [
                    'transaction_id' => $transaction->id,
                    'operating_box_id' => $operatingBox->id,
                    'nuevo_saldo' => $operatingBox->saldo
                ]
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Error al procesar el pago del reembolso',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
