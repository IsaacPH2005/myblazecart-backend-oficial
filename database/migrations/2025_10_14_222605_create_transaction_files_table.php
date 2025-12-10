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
        Schema::create('transaction_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('financial_transaction_id')->constrained('financial_transactions')->onDelete('cascade')->onUpdate('cascade');
            $table->string('ruta')->comment('Ruta del archivo adjunto');
            $table->string('nombre_original')->comment('Nombre del archivo adjunto');
            $table->string('mime_type')->nullable()->comment('Tipo de archivo, por ejemplo: imagen, pdf, etc.');
            $table->boolean('estado')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaction_files');
    }
};
