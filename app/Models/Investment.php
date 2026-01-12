<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Investment extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id',
        'user_id',
        'business_id',
        'monto_inversion',
        'descripcion',
        'notas',
        'active',
        'estado',
    ];

    protected $casts = [
        'monto_inversion' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * Relación con el usuario inversionista
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el vehículo
     */
    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * Relación con el negocio
     */
    public function business()
    {
        return $this->belongsTo(Business::class);
    }

    /**
     * Scope para inversiones activas
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    /**
     * Scope por estado
     */
    public function scopeByEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }
}
