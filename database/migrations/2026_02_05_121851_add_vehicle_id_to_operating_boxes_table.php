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
        if (!Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                $table->unsignedBigInteger('vehicle_id')
                    ->nullable()
                    ->after('negocio_id');

                $table->index('vehicle_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                // Intenta eliminar el índice, ignora si no existe
                try {
                    $table->dropIndex(['vehicle_id']);
                } catch (\Exception $e) {
                    // Índice no existe, continuar
                }

                $table->dropColumn('vehicle_id');
            });
        }
    }
};
