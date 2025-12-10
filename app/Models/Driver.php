<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Driver extends Model
{
    protected $fillable = [
        'user_id',
        'numero_licencia',
        'vencimiento_licencia',
        'estado_licencia',
        'clase_cdl',
        'tipo_licencia',
        'restricciones',
        'categoria',
        'estado', // Asegurar que este campo esté incluido
        'observaciones',
        'foto'
    ];

    // Valor por defecto para el campo estado
    protected $attributes = [
        'estado' => true, // Por defecto, los conductores están activos
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function documents()
    {
        return $this->hasMany(DriverDocument::class);
    }

    // En el modelo Driver
    public function pagosPendientes()
    {
        return $this->hasMany(PendingPayment::class);
    }
}
