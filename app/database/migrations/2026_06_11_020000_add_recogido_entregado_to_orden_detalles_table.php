<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('orden_detalles', 'recogido_en')) {
                $table->timestamp('recogido_en')->nullable();
            }
            if (!Schema::hasColumn('orden_detalles', 'entregado_en')) {
                $table->timestamp('entregado_en')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropColumn(['recogido_en', 'entregado_en']);
        });
    }
};
