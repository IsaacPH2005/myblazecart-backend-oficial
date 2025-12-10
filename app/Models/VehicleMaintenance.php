<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleMaintenance extends Model
{
    protected $fillable = [
        'vehicle_id',
        'tipo',
        'descripcion',
        'fecha_programada',
        'kilometraje_programado',
        'kilometraje_real',
        'costo',
        'estado',
        'archivo',
        'observaciones',
    ];

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
