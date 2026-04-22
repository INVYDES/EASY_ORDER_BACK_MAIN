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
        Schema::create('producto_ingrediente', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('producto_id')->constrained('productos')->onDelete('cascade');
            $blueprint->foreignId('ingrediente_id')->constrained('ingredientes')->onDelete('cascade');
            $blueprint->decimal('cantidad', 12, 4)->default(0);
            $blueprint->timestamps();

            // Índice para mejorar rendimiento de búsquedas de recetas
            $blueprint->index(['producto_id', 'ingrediente_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_ingrediente');
    }
};
