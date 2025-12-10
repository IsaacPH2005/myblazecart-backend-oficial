<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DriverDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'tipo',
        'nombre',
        'archivo',
        'fecha_vencimiento',
        'aprobado',
        'observaciones'
    ];

    protected $appends = ['archivo_url'];

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function getArchivoUrlAttribute()
    {
        return $this->archivo ? asset($this->archivo) : null;
    }
}
