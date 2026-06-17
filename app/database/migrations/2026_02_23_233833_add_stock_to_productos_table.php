<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Verificar si la columna stock NO existe antes de agregarla
            if (!Schema::hasColumn('productos', 'stock')) {
                $table->integer('stock')->default(0)->after('precio');
            }
            
            // Verificar si la columna stock_minimo NO existe antes de agregarla
            if (!Schema::hasColumn('productos', 'stock_minimo')) {
                $table->integer('stock_minimo')->default(5)->after('stock');
            }
        });
    }

    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            // Solo eliminar si existen
            if (Schema::hasColumn('productos', 'stock_minimo')) {
                $table->dropColumn('stock_minimo');
            }
            if (Schema::hasColumn('productos', 'stock')) {
                $table->dropColumn('stock');
            }
        });
    }
};
