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
        Schema::table('operating_boxes', function (Blueprint $table) {
            // Verificar si la columna ya existe antes de agregarla
            if (!Schema::hasColumn('operating_boxes', 'vehicle_id')) {
                $table->unsignedBigInteger('vehicle_id')
                    ->nullable()
                    ->after('negocio_id');

                // Agregar índice para mejorar performance
                $table->index('vehicle_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operating_boxes', function (Blueprint $table) {
            if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
                $table->dropIndex(['vehicle_id']);
                $table->dropColumn('vehicle_id');
            }
        });
    }
};
