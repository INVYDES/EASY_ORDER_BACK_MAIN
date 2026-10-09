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
        Schema::create('impresoras_nube', function (Blueprint $table) {
            $table->id();
            $table->string('restaurante_id');
            $table->string('nombre'); // ej: Cocina, Barra, Caja
            $table->string('modelo')->nullable(); // ej: Star TSP143, Epson TM-m30
            $table->string('token')->unique(); // El token que la impresora usa en la URL
            $table->string('formato')->default('starprnt'); // starprnt, epson, escpos
            $table->boolean('is_active')->default(true);
            $table->json('configuracion')->nullable();
            $table->timestamp('last_polling_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('impresoras_nube');
    }
};
