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
        Schema::create('sesiones_empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('restaurante_id')->nullable()->constrained('restaurantes')->onDelete('cascade');
            $table->foreignId('propietario_id')->nullable()->constrained('propietarios')->onDelete('cascade');
            $table->timestamp('hora_entrada')->useCurrent();
            $table->timestamp('hora_salida')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sesiones_empleados');
    }
};
