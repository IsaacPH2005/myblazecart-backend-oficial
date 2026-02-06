<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

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

                // Agregar índice
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
            // Verificar si existe el índice antes de eliminarlo
            $indexExists = DB::select(
                "SHOW INDEX FROM operating_boxes WHERE Key_name = 'operating_boxes_vehicle_id_index'"
            );

            if (!empty($indexExists)) {
                $table->dropIndex(['vehicle_id']);
            }

            // Verificar si existe la columna antes de eliminarla
            if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
                $table->dropColumn('vehicle_id');
            }
        });
    }
};
