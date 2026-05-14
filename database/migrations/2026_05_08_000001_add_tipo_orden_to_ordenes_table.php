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
        Schema::table('ordenes', function (Blueprint $table) {
            if (!Schema::hasColumn('ordenes', 'tipo_orden')) {
                $table->enum('tipo_orden', ['local', 'pickup', 'delivery'])->default('local')->after('cliente_id');
            }
            if (!Schema::hasColumn('ordenes', 'direccion_entrega')) {
                $table->string('direccion_entrega', 500)->nullable()->after('tipo_orden');
            }
            if (!Schema::hasColumn('ordenes', 'telefono_contacto')) {
                $table->string('telefono_contacto', 20)->nullable()->after('direccion_entrega');
            }
            if (!Schema::hasColumn('ordenes', 'costo_envio')) {
                $table->decimal('costo_envio', 10, 2)->default(0)->after('telefono_contacto');
            }
            if (!Schema::hasColumn('ordenes', 'tiempo_estimado_entrega')) {
                $table->integer('tiempo_estimado_entrega')->nullable()->after('costo_envio');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $columns = ['tipo_orden', 'direccion_entrega', 'telefono_contacto', 'costo_envio', 'tiempo_estimado_entrega'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('ordenes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
