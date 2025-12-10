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
        Schema::create('operating_box_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operating_box_id')->constrained('operating_boxes')->onDelete('cascade')->onUpdate('cascade');
            $table->decimal('monto', 15, 2)->comment('Monto del movimiento (positivo para ingresos, negativo para egresos)');
            $table->decimal('saldo_anterior', 15, 2)->comment('Saldo antes del movimiento');
            $table->decimal('saldo_nuevo', 15, 2)->comment('Saldo después del movimiento');
            $table->string('tipo_movimiento')->comment('ingreso, egreso, ajuste, etc.');
            $table->text('descripcion')->nullable()->comment('Descripción del movimiento');
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();

            // Índices para mejorar el rendimiento
            $table->index(['operating_box_id', 'created_at']);
            $table->index('tipo_movimiento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operating_box_histories');
    }
};
