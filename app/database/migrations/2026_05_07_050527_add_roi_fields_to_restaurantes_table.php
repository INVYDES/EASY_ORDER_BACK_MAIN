<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
 public function up(): void
{
    Schema::table('restaurantes', function (Blueprint $table) {
        $table->decimal('inversion_inicial', 12, 2)->default(0)->after('id');
        $table->decimal('utilidad_objetivo_mensual', 12, 2)->default(0)->after('inversion_inicial');
    });
}

public function down(): void
{
    Schema::table('restaurantes', function (Blueprint $table) {
        $table->dropColumn(['inversion_inicial', 'utilidad_objetivo_mensual']);
    });
}
};
