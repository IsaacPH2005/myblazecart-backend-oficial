<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OperatingBox extends Model
{
    use HasFactory;

    protected $table = 'operating_boxes';

    protected $fillable = [
        'negocio_id',
        'vehicle_id',
        'nombre',
        'saldo',
        'descripcion',
        'estado',
    ];

    protected $casts = [
        'saldo' => 'decimal:2',
        'estado' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $attributes = [
        'estado' => true,
        'saldo' => 0,
    ];

    /**
     * Relación con el negocio
     */
    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }

    /**
     * Relación con el vehículo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    /**
     * Relación con el historial de movimientos
     */
    public function histories()
    {
        return $this->hasMany(OperatingBoxHistorie::class, 'operating_box_id');
    }

    /**
     * Scope para cajas activas
     */
    public function scopeActive($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope para filtrar por negocio
     */
    public function scopeByBusiness($query, $negocioId)
    {
        return $query->where('negocio_id', $negocioId);
    }

    /**
     * Scope para filtrar por vehículo
     */
    public function scopeByVehicle($query, $vehicleId)
    {
        return $query->where('vehicle_id', $vehicleId);
    }

    /**
     * Obtener el saldo formateado
     */
    public function getSaldoFormateadoAttribute()
    {
        return number_format($this->saldo, 2, '.', ',');
    }

    /**
     * Verificar si la caja está activa
     */
    public function isActive()
    {
        return $this->estado === true;
    }

    /**
     * Activar la caja
     */
    public function activate()
    {
        $this->update(['estado' => true]);
    }

    /**
     * Desactivar la caja
     */
    public function deactivate()
    {
        $this->update(['estado' => false]);
    }

    /**
     * Agregar saldo
     */
    public function addBalance($amount, $description = null)
    {
        $oldBalance = $this->saldo;
        $newBalance = $oldBalance + $amount;

        $this->update(['saldo' => $newBalance]);

        // Registrar en el historial
        $this->histories()->create([
            'tipo_movimiento' => 'ingreso',
            'monto' => $amount,
            'saldo_anterior' => $oldBalance,
            'saldo_nuevo' => $newBalance,
            'descripcion' => $description ?? 'Ingreso a caja operativa',
            'fecha_movimiento' => now(),
        ]);

        return $this;
    }

    /**
     * Restar saldo
     */
    public function subtractBalance($amount, $description = null)
    {
        $oldBalance = $this->saldo;
        $newBalance = $oldBalance - $amount;

        if ($newBalance < 0) {
            throw new \Exception('Saldo insuficiente en la caja operativa.');
        }

        $this->update(['saldo' => $newBalance]);

        // Registrar en el historial
        $this->histories()->create([
            'tipo_movimiento' => 'egreso',
            'monto' => $amount,
            'saldo_anterior' => $oldBalance,
            'saldo_nuevo' => $newBalance,
            'descripcion' => $description ?? 'Egreso de caja operativa',
            'fecha_movimiento' => now(),
        ]);

        return $this;
    }

    /**
     * Obtener total de ingresos
     */
    public function getTotalIngresosAttribute()
    {
        return $this->histories()
            ->whereIn('tipo_movimiento', ['ingreso', 'ajuste_ingreso', 'apertura'])
            ->sum('monto');
    }

    /**
     * Obtener total de egresos
     */
    public function getTotalEgresosAttribute()
    {
        return $this->histories()
            ->whereIn('tipo_movimiento', ['egreso', 'ajuste_egreso'])
            ->sum('monto');
    }

    /**
     * Obtener cantidad de movimientos
     */
    public function getCantidadMovimientosAttribute()
    {
        return $this->histories()->count();
    }
}
