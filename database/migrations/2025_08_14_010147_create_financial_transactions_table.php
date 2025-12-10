<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('financial_transactions', function (Blueprint $table) {
            $table->id('id');
            $table->foreignId('negocio_id')->nullable()->constrained('businesses')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('metodo_id')->nullable()->constrained('payment_methods')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('categoria_id')->nullable()->constrained('categories')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('estado_de_transaccion_id')->nullable()->constrained('transaction_states')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('caja_operativa_id')->nullable()->constrained('operating_boxes')->onDelete('cascade')->onUpdate('cascade');
            $table->date('fecha')->nullable();
            $table->string('punto_de_partida')->nullable();
            $table->string('destino')->nullable();
            $table->integer('millas')->nullable();
            $table->string('tipo_de_transaccion')->nullable();
            $table->string('numero_transaccion')->nullable();
            $table->string('item')->nullable();
            $table->decimal('cantidad', 10, 2)->nullable();
            $table->decimal('importe_total', 10, 2)->nullable();
            $table->string('cliente_proveedor')->nullable();
            $table->boolean('egreso_directo')->nullable()->default(false); //true: Egreso directo, false: Egreso indirecto
            $table->text('observaciones')->nullable();
            $table->boolean('estado')->nullable()->default(true);
            $table->string('archivo')->nullable()->comment('Ruta del archivo adjunto, si aplica');
            $table->decimal('monto_excedido', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
