<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleDocument extends Model
{
    protected $fillable = [
        'vehicle_id',
        'tipo',
        'nombre',
        'archivo',
        'fecha_vencimiento',
        'observaciones',
        'aprobado'
    ];

    // Agregar este mutador
    public function setAprobadoAttribute($value)
    {
        $this->attributes['aprobado'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
