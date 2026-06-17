<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roi_config', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurante_id');
            $table->decimal('inversion_inicial', 12, 2)->default(0);
            $table->decimal('utilidad_objetivo', 12, 2)->default(0);
            // Gastos operativos fijos mensuales
            $table->decimal('gasto_renta', 12, 2)->default(0);
            $table->decimal('gasto_servicios', 12, 2)->default(0);
            $table->decimal('gasto_software', 12, 2)->default(0);
            $table->decimal('gasto_marketing', 12, 2)->default(0);
            $table->timestamps();

            $table->foreign('restaurante_id')->references('id')->on('restaurantes')->onDelete('cascade');
            $table->unique('restaurante_id'); // 1 config por restaurante
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roi_config');
    }
};