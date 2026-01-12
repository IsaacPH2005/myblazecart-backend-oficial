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
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->onDelete('set null')->onUpdate('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null')->onUpdate('cascade');
            $table->foreignId('business_id')->nullable()->constrained('businesses')->onDelete('set null')->onUpdate('cascade');
            $table->decimal('monto_inversion', 10, 2)->nullable();
            $table->text('descripcion')->nullable();
            $table->text('notas')->nullable();
            $table->boolean('active')->default(true);
            $table->enum('estado', [
                'pendiente',
                'activo',
                'completado',
                'cancelado'
            ])->default('pendiente');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
