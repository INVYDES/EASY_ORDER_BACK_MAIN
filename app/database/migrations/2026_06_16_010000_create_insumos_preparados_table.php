<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos_preparados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->constrained()->onDelete('cascade');
            $table->string('nombre', 100);
            $table->string('unidad', 30);
            $table->decimal('costo_unitario', 12, 4)->default(0);
            $table->decimal('stock_actual', 12, 3)->default(0);
            $table->decimal('stock_minimo', 12, 3)->default(0);
            $table->integer('vida_util_dias')->nullable()->comment('Días de vida útil del insumo preparado');
            $table->boolean('activo')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index('restaurante_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos_preparados');
    }
};
