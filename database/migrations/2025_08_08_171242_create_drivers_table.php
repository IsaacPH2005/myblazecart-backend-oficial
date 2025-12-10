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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade')->onUpdate('cascade');
            $table->string('numero_licencia')->unique();
            $table->date('vencimiento_licencia')->nullable();
            $table->string('estado_licencia')->nullable();
            $table->string('clase_cdl')->nullable();
            $table->string('tipo_licencia')->nullable();
            $table->string('restricciones')->nullable();
            $table->string('categoria')->nullable();
            $table->boolean('estado')->default(true);
            $table->string('observaciones')->nullable();
            $table->string('foto')->nullable(); // Ruta de la foto del conductor
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
