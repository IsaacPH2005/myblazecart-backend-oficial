<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $fillable = [
        'user_id',
        'negocio_id',
        'numero_vin',
        'codigo_unico',
        'marca',
        'modelo',
        'año',
        'numero_placa',
        'numero_dot',
        'tipo_vehiculo',
        'tipo_propiedad',
        'precio_compra',
        'fecha_compra',
        'valor_actual',
        'millaje',
        'vencimiento_registro',
        'vencimiento_inspeccion',
        'color',
        'combustible',
        'transmision',
        'capacidad_carga',
        'estado',
        'observaciones',
        'foto'
    ];

    protected $casts = [
        'precio_compra' => 'decimal:2',
        'valor_actual' => 'decimal:2',
        'millaje' => 'decimal:2',
        'capacidad_carga' => 'decimal:2',
        'estado' => 'boolean',
        'fecha_compra' => 'date',
        'vencimiento_registro' => 'date',
        'vencimiento_inspeccion' => 'date',
    ];

    /**
     * Inversiones asociadas al vehículo
     */
    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }

    public function documents()
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function maintenances()
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    /**
     * Relación con cajas operativas
     */
    public function operatingBoxes()
    {
        return $this->hasMany(OperatingBox::class, 'vehicle_id');
    }

    /**
     * Scope para vehículos activos
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
}
