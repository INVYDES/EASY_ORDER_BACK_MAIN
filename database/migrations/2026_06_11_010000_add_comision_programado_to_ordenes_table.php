<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            if (!Schema::hasColumn('ordenes', 'comision_pct')) {
                $table->decimal('comision_pct', 5, 2)->nullable()->after('propina');
            }
            if (!Schema::hasColumn('ordenes', 'comision_monto')) {
                $table->decimal('comision_monto', 10, 2)->nullable()->after('comision_pct');
            }
            if (!Schema::hasColumn('ordenes', 'neto_depositar')) {
                $table->decimal('neto_depositar', 10, 2)->nullable()->after('comision_monto');
            }
            if (!Schema::hasColumn('ordenes', 'programado_para')) {
                $table->timestamp('programado_para')->nullable()->after('lista_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropColumn(['comision_pct', 'comision_monto', 'neto_depositar', 'programado_para']);
        });
    }
};
