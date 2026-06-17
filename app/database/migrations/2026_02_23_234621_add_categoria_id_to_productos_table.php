<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Verificar si la columna ya existe antes de agregarla
            if (!Schema::hasColumn('productos', 'categoria_id')) {
                $table->unsignedBigInteger('categoria_id')->nullable()->after('restaurante_id');
                $table->foreign('categoria_id')->references('id')->on('categorias')->onDelete('set null');
                $table->index('categoria_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropForeign(['categoria_id']);
            $table->dropColumn('categoria_id');
        });
    }
};