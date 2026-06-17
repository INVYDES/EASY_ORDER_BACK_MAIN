<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->integer('comensal_id')->nullable()->after('nom_comensal');
        });
    }

    public function down(): void
    {
        Schema::table('orden_detalles', function (Blueprint $table) {
            $table->dropColumn('comensal_id');
        });
    }
};
