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
        Schema::table('productos', function (Blueprint $table) {
            $table->boolean('tiene_tamanos')->default(false)->after('precio_grande');
            $table->decimal('stock_pequeno', 10, 2)->nullable()->after('tiene_tamanos');
            $table->decimal('stock_mediano', 10, 2)->nullable()->after('stock_pequeno');
            $table->decimal('stock_grande', 10, 2)->nullable()->after('stock_mediano');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('productos', function (Blueprint $table) {
            $table->dropColumn([
                'tiene_tamanos',
                'stock_pequeno',
                'stock_mediano',
                'stock_grande'
            ]);
        });
    }
};
