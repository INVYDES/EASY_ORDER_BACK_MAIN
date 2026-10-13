<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            if (!Schema::hasColumn('anuncios', 'imagen')) {
                $table->string('imagen', 255)->nullable()->after('color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('anuncios', function (Blueprint $table) {
            if (Schema::hasColumn('anuncios', 'imagen')) {
                $table->dropColumn('imagen');
            }
        });
    }
};
