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
        // migration
        Schema::create('pending_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->nullable()->constrained('businesses')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->onDelete('cascade')->onUpdate('cascade'); // Asumiendo que tienes una tabla drivers
            $table->foreignId('financial_transaction_id')->nullable()->constrained('financial_transactions')->onDelete('cascade')->onUpdate('cascade');
            $table->decimal('monto', 10, 2);
            $table->string('descripcion')->nullable();
            $table->string('estado')->default('pendiente'); // pendiente, pagado, cancelado
            $table->timestamp('fecha_pago')->nullable();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pending_payments');
    }
};
