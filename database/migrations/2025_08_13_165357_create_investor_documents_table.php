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
        Schema::create('investor_documents', function (Blueprint $table) {
            $table->id()->comment('Identificador único del documento');
            $table->foreignId('investor_id')->constrained('investors')->onDelete('cascade')->onUpdate('cascade');
            $table->enum('tipo', [
                'contrato_inversion',
                'comprobante_pago',
                'identificacion',
                'estado_financiero',
                'certificado_fiscal',
                'otros'
            ])->comment('Tipo de documento: Contrato de Inversión, Comprobante de Pago, Identificación, Estado Financiero, Certificado Fiscal u Otros');
            $table->string('nombre')->comment('Nombre del documento');
            $table->string('archivo')->comment('Ruta del archivo subido');
            $table->date('fecha_vencimiento')->nullable()->comment('Fecha de vencimiento del documento, si aplica');
            $table->boolean('aprobado')->default(false)->comment('Indica si el documento ha sido aprobado');
            $table->text('observaciones')->nullable()->comment('Notas o comentarios adicionales sobre el documento');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('investor_documents');
    }
};
