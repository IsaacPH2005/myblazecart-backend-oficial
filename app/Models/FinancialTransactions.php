<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialTransactions extends Model
{
    protected $table = 'financial_transactions';

    protected $fillable = [
        'negocio_id',
        'metodo_id',
        'categoria_id',
        'user_id',
        'vehicle_id',
        'estado_de_transaccion_id',
        'fecha',
        'punto_de_partida',
        'destino',
        'millas',
        'tipo_de_transaccion',
        'item',
        'cantidad',
        'importe_total',
        'cliente_proveedor',
        'subcategoria',
        'observaciones',
        'estado',
        'numero_transaccion',
        'monto_excedido',
        'caja_operativa_id',
    ];

    // En tu modelo FinancialTransactions
    protected $casts = [
        'egreso_directo' => 'boolean',
        'importe_total' => 'float',
        'cantidad' => 'float',
        'millas' => 'float',
        'fecha' => 'date',
        'estado' => 'boolean'
    ];

    // Relationships
    public function negocio()
    {
        return $this->belongsTo(Business::class, 'negocio_id');
    }

    public function metodo()
    {
        return $this->belongsTo(PaymentMethod::class, 'metodo_id');
    }

    public function categoria()
    {
        return $this->belongsTo(Category::class, 'categoria_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function estadoDeTransaccion()
    {
        return $this->belongsTo(TransactionStates::class, 'estado_de_transaccion_id');
    }

    public function movimientosCaja()
    {
        return $this->hasMany(MovementsBox::class, 'transaccion_financiera_id');
    }

    // Relación con OperatingBox (Caja Operativa)
    public function cajaOperativa()
    {
        return $this->belongsTo(OperatingBox::class, 'caja_operativa_id');
    }

    // Relación con el historial de cajas operativas
    public function operatingBoxHistories()
    {
        return $this->hasMany(OperatingBoxHistorie::class, 'financial_transaction_id');
    }

    // En el modelo FinancialTransaction
    public function pendingPayment()
    {
        return $this->hasOne(PendingPayment::class, 'financial_transaction_id', 'id');
    }

    public function archivos()
    {
        return $this->hasMany(TransactionFile::class, 'financial_transaction_id');
    }
}
