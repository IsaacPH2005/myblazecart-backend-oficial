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
        Schema::create('general_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');

            // Datos personales
            $table->string('nombre');
            $table->string('apellido');
            $table->string('documento_identidad')->unique()->comment('DNI, RUC, Pasaporte, etc.');
            $table->string('celular');
            $table->date('nacimiento')->nullable();
            $table->enum('genero', ['masculino', 'femenino', 'otro'])->nullable();

            // Ubicación
            $table->string('direccion');
            $table->string('ciudad');
            $table->string('departamento')->comment('Estado/Provincia');
            $table->string('codigo_postal', 20)->nullable();

            // Contactos de emergencia
            $table->string('contacto_emergencia_nombre')->nullable();
            $table->string('contacto_emergencia_telefono')->nullable();

            // Información adicional
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('general_data');
    }
};
