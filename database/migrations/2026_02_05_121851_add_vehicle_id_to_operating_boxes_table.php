<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        if (Schema::hasColumn('operating_boxes', 'vehicle_id')) {
            Schema::table('operating_boxes', function (Blueprint $table) {
                $table->dropColumn('vehicle_id');
            });
        }
    }
};
