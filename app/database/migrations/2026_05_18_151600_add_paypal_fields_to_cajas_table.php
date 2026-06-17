<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            if (!Schema::hasColumn('cajas', 'ventas_paypal')) {
                $table->decimal('ventas_paypal', 10, 2)->default(0)->after('ventas_transferencia');
            }
            if (!Schema::hasColumn('cajas', 'ventas_mercadopago')) {
                $table->decimal('ventas_mercadopago', 10, 2)->default(0)->after('ventas_paypal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            if (Schema::hasColumn('cajas', 'ventas_paypal')) {
                $table->dropColumn('ventas_paypal');
            }
            if (Schema::hasColumn('cajas', 'ventas_mercadopago')) {
                $table->dropColumn('ventas_mercadopago');
            }
        });
    }
};
