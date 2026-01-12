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
        return $this->belongsTo(Business::class);
    }
    public function documents()
    {
        return $this->hasMany(VehicleDocument::class);
    }
    public function maintenances()
    {
        return $this->hasMany(VehicleMaintenance::class);
    }
}
