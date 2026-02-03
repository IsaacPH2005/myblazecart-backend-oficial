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
        Schema::create('timeline_events', function (Blueprint $table) {
            $table->id();

            // Relación polimórfica - puede ser User o Business
            $table->morphs('owner'); // owner_type, owner_id

            // Relacionado con la inversión (opcional)
            $table->foreignId('investment_id')->nullable()
                ->constrained('investments')
                ->cascadeOnDelete();

            // Datos del evento
            $table->string('titulo');
            $table->text('descripcion')->nullable();
            $table->string('logo')->nullable();
            $table->string('icono')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();

            $table->enum('estado', [
                'pendiente',
                'en_proceso',
                'completado',
                'cancelado'
            ])->default('pendiente');

            $table->string('color')->default('#3B82F6');
            $table->integer('orden')->default(0);

            // Metadata extra
            $table->decimal('monto', 10, 2)->nullable();
            $table->string('tipo_evento')->nullable(); // 'inversion', 'pago', 'hito', etc.

            $table->timestamps();

            // Índices para búsqueda optimizada
            $table->index(['owner_type', 'owner_id', 'fecha_inicio']);
            $table->index('investment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('timeline_events');
    }
};
