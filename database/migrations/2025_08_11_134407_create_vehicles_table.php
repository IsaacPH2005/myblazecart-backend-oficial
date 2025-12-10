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
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('negocio_id')->nullable()->constrained('businesses')->onDelete('cascade')->onUpdate('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('numero_vin')->nullable();
            $table->string('codigo_unico')->nullable();
            $table->string('marca')->nullable();
            $table->string('modelo')->nullable();
            $table->year('año')->nullable();
            $table->string('numero_placa')->nullable();
            $table->string('numero_dot')->nullable();
            $table->string('tipo_vehiculo')->nullable(); // truck, trailer, semi, box_truck
            $table->string('tipo_propiedad')->nullable(); // owned, leased, lease_on, flip_candidate
            $table->decimal('precio_compra', 12, 2)->nullable();
            $table->date('fecha_compra')->nullable();
            $table->decimal('valor_actual', 12, 2)->nullable();
            $table->integer('millaje')->default(0);
            $table->date('vencimiento_registro')->nullable();
            $table->date('vencimiento_inspeccion')->nullable();
            $table->string('color')->nullable();
            $table->string('combustible')->nullable(); // diesel, gasolina, hibrido, electrico
            $table->string('transmision')->nullable(); // manual, automatica
            $table->integer('capacidad_carga')->nullable(); // en libras o toneladas
            $table->string('estado')->default('activo'); // activo, mantenimiento, inactivo, en_venta, vendido
            $table->boolean('is_active')->default(true); // Indica si el vehículo está activo
            $table->string('observaciones')->nullable();
            $table->string('foto')->nullable(); // Ruta de la foto del vehículo
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
