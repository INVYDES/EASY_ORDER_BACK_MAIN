<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurante_id')->nullable()->constrained('restaurantes')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('usuario_nombre')->nullable(); // Para externos
            $table->string('contacto')->nullable();
            $table->text('mensaje');
            $table->string('clasificacion')->default('DUDA_OPERATIVA'); // ERROR_CRITICO, FALLA_SISTEMA, SUGERENCIA, SPAM, etc.
            $table->string('prioridad')->default('BAJA'); // ALTA, MEDIA, BAJA
            $table->string('estado')->default('pendiente'); // pendiente, en_proceso, resuelto, descartado
            $table->text('respuesta_ia')->nullable();
            $table->text('notas_admin')->nullable();
            $table->json('metadata')->nullable(); // Para guardar el JSON completo de Gemini
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurante_id', 'estado']);
            $table->index('clasificacion');
            $table->index('prioridad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
