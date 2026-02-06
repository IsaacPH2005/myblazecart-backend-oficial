<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                $table->unsignedBigInteger('vehicle_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        // 1. Primero eliminar foreign key si existe
        try {
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME
                FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'operating_boxes'
                AND COLUMN_NAME = 'vehicle_id'
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            foreach ($foreignKeys as $fk) {
                DB::statement("ALTER TABLE operating_boxes DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}");
            }
        } catch (\Exception $e) {
            // Foreign key no existe
        }

        // 2. Luego eliminar índices
        try {
            $indexes = DB::select("SHOW INDEX FROM operating_boxes WHERE Column_name = 'vehicle_id'");
            foreach ($indexes as $index) {
                if ($index->Key_name !== 'PRIMARY') {
                    DB::statement("DROP INDEX {$index->Key_name} ON operating_boxes");
                }
            }
        } catch (\Exception $e) {
            // Índice no existe
        }

        // 3. Finalmente eliminar la columna
        if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                $table->dropColumn('vehicle_id');
            });
        }
    }
};
