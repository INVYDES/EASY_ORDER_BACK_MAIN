<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caja_movimientos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('caja_id');
            $table->unsignedBigInteger('usuario_id');
            
            $table->enum('tipo', ['ingreso', 'egreso', 'apertura']);
            $table->decimal('monto', 10, 2);
            $table->string('descripcion');
            $table->string('referencia')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('caja_id')->references('id')->on('cajas')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('users');
            
            $table->index(['caja_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_movimientos');
    }
}; 