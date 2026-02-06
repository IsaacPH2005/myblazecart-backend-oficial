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
            // Primero verifica si la columna ya existe
            if (!Schema::hasColumn('operating_boxes', 'vehicle_id')) {
                $table->unsignedBigInteger('vehicle_id')
                    ->nullable()
                    ->after('negocio_id');

                // Luego crea la foreign key solo si la tabla vehicles existe
                if (Schema::hasTable('vehicles')) {
                    $table->foreign('vehicle_id')
                        ->references('id')
                        ->on('vehicles')
                        ->onDelete('set null')  // ⚠️ Cambiado a SET NULL para no perder cajas
                        ->onUpdate('cascade');
                }
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
                // Eliminar foreign key primero
                $table->dropForeign(['vehicle_id']);
                // Luego eliminar la columna
                $table->dropColumn('vehicle_id');
            }
        });
    }
};
