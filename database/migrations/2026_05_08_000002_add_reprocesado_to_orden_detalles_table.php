<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            if (!Schema::hasColumn('orden_detalles', 'reprocesado')) {
                $table->boolean('reprocesado')->default(false)->after('estado_preparacion');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            if (Schema::hasColumn('orden_detalles', 'reprocesado')) {
                $table->dropColumn('reprocesado');
            }
        });
    }
};
