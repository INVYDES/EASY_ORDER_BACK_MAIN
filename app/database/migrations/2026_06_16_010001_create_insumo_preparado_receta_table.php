<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumo_preparado_receta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insumo_preparado_id')->constrained('insumos_preparados')->onDelete('cascade');
            $table->foreignId('ingrediente_id')->constrained()->onDelete('cascade');
            $table->decimal('cantidad', 12, 3)->comment('Cantidad del ingrediente necesaria para preparar una unidad del insumo');
            $table->timestamps();

            $table->unique(['insumo_preparado_id', 'ingrediente_id'], 'insumo_receta_unique');
            $table->index('insumo_preparado_id');
            $table->index('ingrediente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumo_preparado_receta');
    }
};
