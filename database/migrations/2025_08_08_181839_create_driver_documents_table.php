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
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('drivers')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('tipo', [
                'licencia',
                'seguro',
                'identificacion',
                'certificado_medico',
                'registro_vehicular',
                'otros'
            ]);
            $table->string('nombre');
            $table->string('archivo'); // Ruta del archivo
            $table->date('fecha_vencimiento')->nullable();
            $table->boolean('aprobado')->default(false);
            $table->text('observaciones')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
