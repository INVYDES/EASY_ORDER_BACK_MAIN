<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            // JSON con días de la semana (0=Dom ... 6=Sáb) en los que debe mostrarse.
            // NULL / [] = todos los días.
            $table->json('dias_semana')->nullable()->after('fecha_fin');
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            $table->dropColumn('dias_semana');
        });
    }
};