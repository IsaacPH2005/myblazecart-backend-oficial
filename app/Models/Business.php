<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Business extends Model
{

    protected $table = 'businesses';

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado',
    ];
    // Una caja operativa tiene muchas categorías
    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'negocio_id');
    }
    public function cajasOperativas()
    {
        return $this->hasMany(OperatingBox::class, 'caja_operativa_id');
    }


    // En el modelo Negocio
    public function pagosPendientes()
    {
        return $this->hasMany(PendingPayment::class);
    }
}
