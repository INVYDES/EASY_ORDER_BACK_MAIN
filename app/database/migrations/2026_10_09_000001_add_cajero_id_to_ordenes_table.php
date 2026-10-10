<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ordenes')) {
            Schema::table('ordenes', function (Blueprint $table) {
                if (!Schema::hasColumn('ordenes', 'cajero_id')) {
                    $table->unsignedBigInteger('cajero_id')->nullable()->after('usuario_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ordenes')) {
            Schema::table('ordenes', function (Blueprint $table) {
                if (Schema::hasColumn('ordenes', 'cajero_id')) {
                    $table->dropColumn('cajero_id');
                }
            });
        }
    }
};
