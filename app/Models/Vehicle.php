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
        'estado' => 'boolean',
        'año' => 'integer',
        'precio_compra' => 'decimal:2',
        'valor_actual' => 'decimal:2',
        'millaje' => 'decimal:2',
        'capacidad_carga' => 'decimal:2',
        'fecha_compra' => 'date',
        'vencimiento_registro' => 'date',
        'vencimiento_inspeccion' => 'date',
    ];

    /**
     * Relación con el usuario propietario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con el negocio
     */
    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }

    /**
     * Inversiones asociadas al vehículo
     */
    public function investments()
    {
        return $this->hasMany(Investment::class);
    }

    /**
     * Documentos del vehículo
     */
    public function documents()
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /**
     * Mantenimientos del vehículo
     */
    public function maintenances()
    {
        return $this->hasMany(VehicleMaintenance::class);
    }

    /**
     * Cajas operativas asociadas al vehículo
     * ⚠️ ESTA ES LA RELACIÓN QUE TE FALTABA ⚠️
     */
    public function operatingBoxes()
    {
        return $this->hasMany(OperatingBox::class, 'vehicle_id');
    }

    /**
     * Obtener la caja operativa activa del vehículo
     */
    public function activeOperatingBox()
    {
        return $this->hasOne(OperatingBox::class, 'vehicle_id')
            ->where('estado', true)
            ->latest();
    }

    /**
     * Scope: Vehículos activos
     */
    public function scopeActive($query)
    {
        return $query->where('estado', true);
    }

    /**
     * Scope: Filtrar por negocio
     */
    public function scopeByBusiness($query, $negocioId)
    {
        return $query->where('negocio_id', $negocioId);
    }

    /**
     * Scope: Filtrar por tipo de vehículo
     */
    public function scopeByType($query, $tipo)
    {
        return $query->where('tipo_vehiculo', $tipo);
    }

    /**
     * Accessor: Nombre completo del vehículo
     */
    public function getDisplayNameAttribute()
    {
        return "{$this->numero_placa} - {$this->marca} {$this->modelo}";
    }

    /**
     * Accessor: Nombre corto
     */
    public function getShortNameAttribute()
    {
        return "{$this->marca} {$this->modelo} ({$this->año})";
    }

    /**
     * Verificar si el vehículo está activo
     */
    public function isActive()
    {
        return $this->estado === true;
    }

    /**
     * Verificar si tiene caja operativa asignada
     */
    public function hasActiveOperatingBox()
    {
        return $this->operatingBoxes()->where('estado', true)->exists();
    }

    /**
     * Obtener el saldo total de cajas operativas
     */
    public function getTotalOperatingBoxBalanceAttribute()
    {
        return $this->operatingBoxes()
            ->where('estado', true)
            ->sum('saldo');
    }
}
