<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = match (true) {
            Schema::hasTable('ingredientes_de_productos') => 'ingredientes_de_productos',
            Schema::hasTable('producto_ingrediente') => 'producto_ingrediente',
            default => null,
        };

        if ($tableName === null) return;

        Schema::table($tableName, function (Blueprint $table) {
            $table->json('cantidades_por_tamano')->nullable()->after('cantidad_grande');
        });
    }

    public function down(): void
    {
        $tableName = match (true) {
            Schema::hasTable('ingredientes_de_productos') => 'ingredientes_de_productos',
            Schema::hasTable('producto_ingrediente') => 'producto_ingrediente',
            default => null,
        };

        if ($tableName === null) return;

        Schema::table($tableName, function (Blueprint $table) {
            $table->dropColumn('cantidades_por_tamano');
        });
    }
};
