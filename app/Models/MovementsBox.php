<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class MovementsBox extends Model
{
    use HasFactory;

    protected $table = 'movements_boxes';

    protected $fillable = [
        'monto',
        'monto_excedido',
        'numero_transaccion',
        'tipo',
        'descripcion',
        'fecha_movimiento',
        'transaccion_financiera_id',
        'user_id'
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'monto_excedido' => 'decimal:2',
        'fecha_movimiento' => 'date',
    ];

    // ============ RELACIONES CORREGIDAS ============

    /**
     * Relación: MovementsBox pertenece a una FinancialTransaction
     */
    public function transaccionFinanciera(): BelongsTo
    {
        return $this->belongsTo(FinancialTransactions::class, 'transaccion_financiera_id', 'id');
    }

    /**
     * Relación: MovementsBox pertenece a un User
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    /**
     * Relación: Obtener la OperatingBox a través de FinancialTransactions
     */
    public function cajaOperativa(): HasOneThrough
    {
        return $this->hasOneThrough(
            OperatingBox::class,
            FinancialTransactions::class,
            'id',                          // Foreign key en financial_transactions
            'id',                          // Foreign key en operating_boxes
            'transaccion_financiera_id',   // Local key en movements_boxes
            'caja_operativa_id'            // Local key en financial_transactions
        );
    }

    /**
     * Relación: Obtener la Categoría a través de FinancialTransactions
     */
    public function categoria()
    {
        return $this->hasManyThrough(
            Category::class,
            FinancialTransactions::class,
            'id',                          // Foreign key en financial_transactions
            'id',                          // Foreign key en categories
            'transaccion_financiera_id',   // Local key en movements_boxes
            'categoria_id'                 // Local key en financial_transactions
        );
    }

    // ============ SCOPES ============

    /**
     * Scope para filtrar movimientos por caja operativa
     */
    public function scopeDeCaja($query, $cajaId)
    {
        return $query->whereHas('transaccionFinanciera', function ($query) use ($cajaId) {
            $query->where('caja_operativa_id', $cajaId);
        });
    }

    /**
     * Scope para filtrar movimientos por rango de fechas
     */
    public function scopeEntreFechas($query, $fechaInicio, $fechaFin)
    {
        return $query->whereBetween('fecha_movimiento', [$fechaInicio, $fechaFin]);
    }

    /**
     * Scope para filtrar por tipo de movimiento
     */
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para filtrar por usuario
     */
    public function scopePorUsuario($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope para ordenar por fecha descendente
     */
    public function scopeOrdenado($query)
    {
        return $query->orderBy('fecha_movimiento', 'desc');
    }
}
