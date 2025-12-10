<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionStates extends Model
{
    protected $table = 'transaction_states';

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado',
    ];
}
