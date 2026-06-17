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
        Schema::table('orden_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('orden_detalles', 'en_preparacion_at')) {
                $table->timestamp('en_preparacion_at')->nullable()->after('estado_preparacion');
            }
            if (!Schema::hasColumn('orden_detalles', 'listo_at')) {
                $table->timestamp('listo_at')->nullable()->after('en_preparacion_at');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropColumn(['en_preparacion_at', 'listo_at']);
        });
    }
};
