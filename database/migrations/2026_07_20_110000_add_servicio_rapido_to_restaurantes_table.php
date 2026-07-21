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
        Schema::table('restaurantes', function (Blueprint $table) {
            if (!Schema::hasColumn('restaurantes', 'servicio_rapido')) {
                $table->boolean('servicio_rapido')->default(false)->after('total_mesas');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurantes', function (Blueprint $table) {
            if (Schema::hasColumn('restaurantes', 'servicio_rapido')) {
                $table->dropColumn('servicio_rapido');
            }
        });
    }
};
