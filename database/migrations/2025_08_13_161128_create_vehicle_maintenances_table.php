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
        Schema::create('vehicle_maintenances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('tipo', [
                'cambio_aceite',
                'rotacion_neumaticos',
                'inspeccion_frenos',
                'alineacion',
                'cambio_filtros',
                'inspeccion_general',
                'cambio_bateria',
                'otros'
            ]);
            $table->string('descripcion')->nullable(); // Breve descripción del mantenimiento
            $table->date('fecha_programada'); // Fecha programada para el mantenimiento
            $table->integer('kilometraje_programado'); // Kilometraje esperado para el mantenimiento
            $table->integer('kilometraje_real')->nullable(); // Kilometraje real al realizar el mantenimiento
            $table->decimal('costo', 10, 2)->nullable(); // Costo del mantenimiento
            $table->enum('estado', ['pendiente', 'completado', 'atrasado'])->default('pendiente');
            $table->string('archivo')->nullable(); // Ruta del archivo (e.g., recibo o informe)
            $table->text('observaciones')->nullable(); // Notas adicionales
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vehicle_maintenances');
    }
};
