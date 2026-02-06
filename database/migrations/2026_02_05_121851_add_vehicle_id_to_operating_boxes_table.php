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
        if (!Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                $table->unsignedBigInteger('vehicle_id')->nullable();

                // Agregar foreign key
                $table->foreign('vehicle_id')
                    ->references('id')
                    ->on('vehicles')
                    ->onDelete('set null')  // Si se elimina el vehículo, la caja queda sin vehículo
                    ->onUpdate('cascade');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operating_boxes', function (Blueprint $table) {
            // 1. Primero eliminar foreign key
            if ($this->foreignKeyExists('operating_boxes', 'operating_boxes_vehicle_id_foreign')) {
                $table->dropForeign(['vehicle_id']);
            }

            // 2. Luego eliminar la columna
            if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
                $table->dropColumn('vehicle_id');
            }
        });
    }

    /**
     * Verificar si existe una foreign key
     */
    private function foreignKeyExists($table, $name)
    {
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
            AND CONSTRAINT_NAME = ?
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ", [$table, $name]);

        return !empty($foreignKeys);
    }
};
