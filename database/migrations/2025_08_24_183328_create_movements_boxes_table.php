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
        Schema::create('movements_boxes', function (Blueprint $table) {
            $table->id();
            $table->decimal('monto', 10, 2)->nullable();
            $table->decimal('monto_excedido', 10, 2)->nullable();
            $table->string('numero_transaccion')->nullable(); // 'ingreso' o 'egreso'
            $table->string('tipo')->nullable(); // 'ingreso' o 'egreso'
            $table->text('descripcion')->nullable();
            $table->dateTime('fecha_movimiento')->nullable();
            $table->foreignId('transaccion_financiera_id')->nullable()->constrained('financial_transactions')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('movements_boxes');
    }
};
