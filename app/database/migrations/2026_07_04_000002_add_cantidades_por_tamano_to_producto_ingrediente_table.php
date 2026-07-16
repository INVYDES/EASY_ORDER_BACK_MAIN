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
        Schema::table('producto_ingrediente', function (Blueprint $table) {
            $table->decimal('cantidad_pequeno', 12, 4)->nullable()->after('cantidad');
            $table->decimal('cantidad_mediano', 12, 4)->nullable()->after('cantidad_pequeno');
            $table->decimal('cantidad_grande', 12, 4)->nullable()->after('cantidad_mediano');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('producto_ingrediente', function (Blueprint $table) {
            $table->dropColumn([
                'cantidad_pequeno',
                'cantidad_mediano',
                'cantidad_grande'
            ]);
        });
    }
};
