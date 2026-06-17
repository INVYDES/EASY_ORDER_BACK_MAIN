<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumo_preparado_movimientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('insumo_preparado_id')->constrained('insumos_preparados')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tipo', 20); // entrada, salida, ajuste
            $table->decimal('cantidad_anterior', 12, 3);
            $table->decimal('cantidad_movimiento', 12, 3);
            $table->decimal('cantidad_nueva', 12, 3);
            $table->string('motivo', 200)->nullable();
            $table->timestamps();

            $table->index('insumo_preparado_id');
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumo_preparado_movimientos');
    }
};
