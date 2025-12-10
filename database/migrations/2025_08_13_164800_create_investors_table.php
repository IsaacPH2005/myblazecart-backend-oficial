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
        Schema::create('investors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('tipo_inversion', [
                'arrendamiento',/* lease_on */
                'compra_venta_camiones',/* truck_flipping */
                'operacional'/* operational */
            ])->default('arrendamiento');
            $table->decimal('monto_inversion', 10, 2);
            $table->decimal('retorno_esperado', 10, 2)->nullable();
            $table->enum('estado', [
                'pendiente',
                'activo',
                'completado',
                'cancelado'
            ])->default('pendiente');
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null')->onUpdate('cascade');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investors');
    }
};
