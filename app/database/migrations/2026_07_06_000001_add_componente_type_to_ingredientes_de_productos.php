<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        \ = match (true) {
            Schema::hasTable('ingredientes_de_productos') => 'ingredientes_de_productos',
            Schema::hasTable('producto_ingrediente') => 'producto_ingrediente',
            default => null,
        };

        if (\ === null) return;

        // Try to drop FK constraint (name varies depending on actual table name)
        try {
            Schema::table(\, fn(Blueprint \) => \->dropForeign([\ === 'producto_ingrediente' ? null : 'ingrediente_id']));
        } catch (\Exception \) {
            // Ignore if FK doesn't exist or has different name
        }

        Schema::table(\, function (Blueprint \) {
            \->string('componente_type', 20)->default('ingrediente')->after('ingrediente_id');
            \->index(['producto_id', 'ingrediente_id', 'componente_type'], 'prod_comp_type_idx');
        });
    }

    public function down(): void
    {
        \ = match (true) {
            Schema::hasTable('ingredientes_de_productos') => 'ingredientes_de_productos',
            Schema::hasTable('producto_ingrediente') => 'producto_ingrediente',
            default => null,
        };

        if (\ === null) return;

        Schema::table(\, function (Blueprint \) {
            \->dropIndex('prod_comp_type_idx');
            \->dropColumn('componente_type');
        });
    }
};
