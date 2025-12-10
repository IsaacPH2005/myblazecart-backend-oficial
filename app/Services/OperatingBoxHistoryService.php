<?php

namespace App\Services;

use App\Models\OperatingBox;
use App\Models\OperatingBoxHistorie;
use App\Models\FinancialTransactions;
use Illuminate\Support\Facades\Auth;

class OperatingBoxHistoryService
{
    /**
     * Registrar un movimiento en el historial de caja operativa
     *
     * @param OperatingBox $operatingBox
     * @param float $monto
     * @param string $tipoMovimiento
     * @param string $descripcion
     * @param FinancialTransactions|null $transaction
     * @param float|null $saldoAnterior
     * @param float|null $saldoNuevo
     */
    public function registrarMovimiento(
        OperatingBox $operatingBox,
        float $monto,
        string $tipoMovimiento,
        string $descripcion,
        ?FinancialTransactions $transaction = null,
        ?float $saldoAnterior = null,
        ?float $saldoNuevo = null
    ): OperatingBoxHistorie {
        // Si no se proporcionan saldos, usar el saldo actual de la caja
        if ($saldoAnterior === null) {
            $saldoAnterior = $operatingBox->saldo;
        }
        if ($saldoNuevo === null) {
            $saldoNuevo = $operatingBox->saldo;
        }

        // Crear registro en el historial
        $historial = OperatingBoxHistorie::create([
            'operating_box_id' => $operatingBox->id,
            'monto' => $monto,
            'saldo_anterior' => $saldoAnterior,
            'saldo_nuevo' => $saldoNuevo,
            'tipo_movimiento' => $tipoMovimiento,
            'descripcion' => $descripcion,
            'financial_transaction_id' => $transaction?->id,
            'user_id' => Auth::id(),
        ]);

        return $historial;
    }

    /**
     * Obtener historial de una caja operativa con filtros
     *
     * @param int $operatingBoxId
     * @param array $filters
     * @return array
     */
    public function obtenerHistorial(int $operatingBoxId, array $filters = []): array
    {
        $operatingBox = OperatingBox::findOrFail($operatingBoxId);
        $query = $operatingBox->historial()
            ->with(['user.generalData', 'financialTransaction']);

        // Extraer el valor de per_page de los filtros
        $perPage = $filters['per_page'] ?? 15;

        // Aplicar filtros
        if (!empty($filters['fecha_desde'])) {
            $query->where('created_at', '>=', $filters['fecha_desde']);
        }
        if (!empty($filters['fecha_hasta'])) {
            $query->where('created_at', '<=', $filters['fecha_hasta']);
        }
        if (!empty($filters['tipo_movimiento'])) {
            $query->where('tipo_movimiento', $filters['tipo_movimiento']);
        }

        // Paginación
        $historial = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return [
            'operating_box' => [
                'id' => $operatingBox->id,
                'nombre' => $operatingBox->nombre,
                'saldo_actual' => $operatingBox->saldo,
                'descripcion' => $operatingBox->descripcion,
                'estado' => $operatingBox->estado,
            ],
            'resumen' => [
                'total_ingresos' => $operatingBox->total_ingresos,
                'total_egresos' => $operatingBox->total_egresos,
                'saldo_neto' => $operatingBox->total_ingresos - $operatingBox->total_egresos,
            ],
            'historial' => $historial->items(),
            'pagination' => [
                'current_page' => $historial->currentPage(),
                'last_page' => $historial->lastPage(),
                'per_page' => $historial->perPage(),
                'total' => $historial->total(),
                'from' => $historial->firstItem(),
                'to' => $historial->lastItem()
            ]
        ];
    }
}
