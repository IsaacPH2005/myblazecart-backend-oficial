<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionFile extends Model
{
    protected $table = 'transaction_files';

    protected $fillable = [
        'financial_transaction_id',
        'ruta',
        'nombre_original',
        'mime_type',
        'estado',
    ];

    // Relación con FinancialTransactions
    public function financialTransaction()
    {
        return $this->belongsTo(FinancialTransactions::class, 'financial_transaction_id');
    }
}
