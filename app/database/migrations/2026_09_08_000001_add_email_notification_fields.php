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
        if (Schema::hasTable('propietario_licencia')) {
            Schema::table('propietario_licencia', function (Blueprint $table) {
                if (!Schema::hasColumn('propietario_licencia', 'notificado_vencimiento_at')) {
                    $table->timestamp('notificado_vencimiento_at')->nullable()->after('proximo_pago_at');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (!Schema::hasColumn('users', 'email_verified_at')) {
                    $table->timestamp('email_verified_at')->nullable()->after('email');
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('propietario_licencia')) {
            Schema::table('propietario_licencia', function (Blueprint $table) {
                if (Schema::hasColumn('propietario_licencia', 'notificado_vencimiento_at')) {
                    $table->dropColumn('notificado_vencimiento_at');
                }
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table) {
                if (Schema::hasColumn('users', 'email_verified_at')) {
                    $table->dropColumn('email_verified_at');
                }
            });
        }
    }
};
