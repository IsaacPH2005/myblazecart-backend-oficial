<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OperatingBoxHistorie extends Model
{
    use HasFactory;

    protected $table = 'operating_box_histories';

    protected $fillable = [
        'operating_box_id',
        'monto',
        'saldo_anterior',
        'saldo_nuevo',
        'tipo_movimiento',
        'descripcion',
        'financial_transaction_id',
        'user_id',
    ];
    protected $casts = [
        'monto' => 'decimal:2',
        'saldo_anterior' => 'decimal:2',
        'saldo_nuevo' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    // Relaciones
    public function operatingBox()
    {
        return $this->belongsTo(OperatingBox::class);
    }

    public function financialTransaction()
    {
        return $this->belongsTo(FinancialTransactions::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
