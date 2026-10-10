<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integración de terminales Mercado Pago Point al punto de venta.
 *
 * - mercadopago_credenciales: tokens OAuth por restaurante (una cuenta MP por cliente).
 * - mercadopago_terminales:   terminales Point emparejadas (una o varias por restaurante).
 * - ordenes:                  referencias de la Orders API para conciliar el cobro.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('mercadopago_credenciales')) {
            Schema::create('mercadopago_credenciales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurante_id');
                $table->string('mp_user_id')->nullable();      // user_id del vendedor en MP
                $table->text('access_token');                  // cifrado (cast encrypted)
                $table->text('refresh_token')->nullable();     // cifrado (cast encrypted)
                $table->string('public_key')->nullable();
                $table->boolean('live_mode')->default(false);
                $table->string('scope')->nullable();
                $table->timestamp('token_expires_at')->nullable();
                $table->timestamp('connected_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('restaurante_id');
                $table->index('mp_user_id');
            });
        }

        if (!Schema::hasTable('mercadopago_terminales')) {
            Schema::create('mercadopago_terminales', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurante_id');
                $table->string('terminal_id');                 // NEWLAND_N950__XXXXXXXX
                $table->string('alias')->nullable();           // "Caja 1", "Mostrador"
                $table->string('store_id')->nullable();        // sucursal en MP
                $table->string('pos_id')->nullable();          // caja/POS en MP
                $table->string('device_serial')->nullable();
                $table->string('operating_mode')->default('PDV');
                $table->string('print_on_terminal')->default('seller_ticket'); // seller_ticket | no_ticket
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_sync_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['restaurante_id', 'terminal_id']);
            });
        }

        // La tabla `ordenes` viene del dump base del proyecto; la alteramos solo si existe.
        if (Schema::hasTable('ordenes')) {
            Schema::table('ordenes', function (Blueprint $table) {
                if (!Schema::hasColumn('ordenes', 'mercadopago_order_id')) {
                    $table->string('mercadopago_order_id')->nullable()->after('mercadopago_payment_id');
                }
                if (!Schema::hasColumn('ordenes', 'mercadopago_terminal_id')) {
                    $table->string('mercadopago_terminal_id')->nullable()->after('mercadopago_order_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ordenes')) {
            Schema::table('ordenes', function (Blueprint $table) {
                if (Schema::hasColumn('ordenes', 'mercadopago_terminal_id')) {
                    $table->dropColumn('mercadopago_terminal_id');
                }
                if (Schema::hasColumn('ordenes', 'mercadopago_order_id')) {
                    $table->dropColumn('mercadopago_order_id');
                }
            });
        }

        Schema::dropIfExists('mercadopago_terminales');
        Schema::dropIfExists('mercadopago_credenciales');
    }
};
