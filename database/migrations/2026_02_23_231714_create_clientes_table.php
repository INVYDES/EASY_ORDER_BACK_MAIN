<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('clientes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurante_id');
            $table->string('nombre');
            $table->string('apellido')->nullable();
            $table->string('email')->nullable()->unique();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->date('fecha_registro')->nullable();
            $table->integer('total_compras')->default(0);
            $table->decimal('gasto_total', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
            $table->softDeletes();
            
            $table->foreign('restaurante_id')->references('id')->on('restaurantes')->onDelete('cascade');
            $table->index('restaurante_id');
            $table->index('email');
            $table->index('telefono');
        });
    }

    public function down()
    {
        Schema::dropIfExists('clientes');
    }
};