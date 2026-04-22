<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cajas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurante_id');
            $table->unsignedBigInteger('usuario_apertura_id');
            $table->unsignedBigInteger('usuario_cierre_id')->nullable();
            
            $table->datetime('fecha_apertura');
            $table->datetime('fecha_cierre')->nullable();
            
            $table->decimal('monto_inicial', 10, 2);
            $table->decimal('monto_final', 10, 2)->nullable();
            
            $table->decimal('ventas_efectivo', 10, 2)->default(0);
            $table->decimal('ventas_tarjeta', 10, 2)->default(0);
            $table->decimal('ventas_transferencia', 10, 2)->default(0);
            $table->integer('total_ordenes')->default(0);
            
            $table->decimal('diferencia', 10, 2)->nullable();
            $table->text('observaciones_cierre')->nullable();
            
            $table->enum('estado', ['abierta', 'cerrada'])->default('abierta');
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('restaurante_id')->references('id')->on('restaurantes')->onDelete('cascade');
            $table->foreign('usuario_apertura_id')->references('id')->on('users');
            $table->foreign('usuario_cierre_id')->references('id')->on('users');
            
            $table->index(['restaurante_id', 'fecha_apertura']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas');
    }
};