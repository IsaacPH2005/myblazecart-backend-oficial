<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GeneralData extends Model
{
    use HasFactory;

    protected $table = 'general_data';

    protected $fillable = [
        'user_id',
        'nombre',
        'apellido',
        'documento_identidad',
        'celular',
        'nacimiento',
        'genero',
        'direccion',
        'ciudad',
        'departamento',
        'codigo_postal',
        'contacto_emergencia_nombre',
        'contacto_emergencia_telefono',
        'notas',
    ];

    protected function casts(): array
    {
        return [
            'nacimiento' => 'date',
        ];
    }

    /**
     * Relación inversa con User
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
