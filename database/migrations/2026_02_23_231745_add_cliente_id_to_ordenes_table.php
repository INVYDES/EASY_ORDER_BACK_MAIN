<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->unsignedBigInteger('cliente_id')->nullable()->after('user_id');
            $table->foreign('cliente_id')->references('id')->on('clientes')->onDelete('set null');
            $table->index('cliente_id');
        });
    }

    public function down()
    {
        Schema::table('ordenes', function (Blueprint $table) {
            $table->dropForeign(['cliente_id']);
            $table->dropColumn('cliente_id');
        });
    }
};