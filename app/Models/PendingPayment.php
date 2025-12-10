<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingPayment extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'pending_payments';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'negocio_id',
        'driver_id',
        'financial_transaction_id',
        'monto',
        'descripcion',
        'estado',
        'fecha_pago',
        'user_id',
    ];
    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'monto' => 'decimal:2',
        'fecha_pago' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    // En el modelo PendingPayment
    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }
    // En el modelo PendingPayment
    public function financialTransaction()
    {
        return $this->belongsTo(FinancialTransactions::class, 'financial_transaction_id');
    }
    // En el modelo PendingPayment
    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }
    // En el modelo PendingPayment
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
