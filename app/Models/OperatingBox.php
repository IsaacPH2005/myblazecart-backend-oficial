<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperatingBox extends Model
{
    protected $table = 'operating_boxes';

    protected $fillable = [
        'nombre',
        'saldo',
        'descripcion',
        'estado',
    ];
    protected $casts = [
        'estado' => 'boolean',
        'saldo' => 'decimal:2',
    ];

    // Relación con FinancialTransaction
    public function transaccionesFinancieras()
    {
        return $this->hasMany(FinancialTransactions::class, 'caja_operativa_id');
    }

    // Relación con Business (Negocio)
    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }
    // Una caja operativa tiene muchos registros de historial
    public function historial()
    {
        return $this->hasMany(OperatingBoxHistorie::class, 'operating_box_id')->orderBy('created_at', 'desc');
    }


    // Métodos para obtener estadísticas
    public function getTotalIngresosAttribute()
    {
        return $this->historial()
            ->where('tipo_movimiento', 'ingreso')
            ->sum('monto');
    }

    public function getTotalEgresosAttribute()
    {
        return abs($this->historial()
            ->where('tipo_movimiento', 'egreso')
            ->sum('monto'));
    }

    public function getUltimoMovimientoAttribute()
    {
        return $this->historial()->first();
    }
}
