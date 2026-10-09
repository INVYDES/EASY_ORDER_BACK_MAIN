<?php

namespace Tests\Feature;

use App\Models\Caja;
use App\Models\CajaMovimientos;
use App\Models\MercadoPagoCredencial;
use App\Models\Orden;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Webhook de Mercado Pago Point (Orders API) y cierre idempotente de la venta.
 *
 * NOTA: el proyecto no incluye migraciones para las tablas base (`ordenes`,
 * `cajas`, `caja_movimientos`) porque vienen del dump; las creamos aquí para
 * poder probar el flujo de punta a punta.
 */
class PointWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const RESTAURANTE_ID = 10;

    protected function setUp(): void
    {
        parent::setUp();

        // El .env real puede traer MERCADOPAGO_WEBHOOK_SECRET; lo aislamos para
        // que las pruebas de pago no dependan de la firma (las de firma la fijan).
        config(['services.mercadopago.webhook_secret' => null]);

        if (!Schema::hasTable('ordenes')) {
            Schema::create('ordenes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurante_id')->nullable();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('tipo_orden')->default('local');
                $table->string('metodo_pago')->nullable();
                $table->string('mercadopago_preference_id')->nullable();
                $table->string('mercadopago_payment_id')->nullable();
                $table->string('mercadopago_order_id')->nullable();
                $table->string('mercadopago_terminal_id')->nullable();
                $table->decimal('total', 10, 2)->default(0);
                $table->decimal('propina', 10, 2)->default(0);
                $table->decimal('costo_envio', 10, 2)->default(0);
                $table->string('estado')->default('ABIERTA');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('cajas')) {
            Schema::create('cajas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('restaurante_id');
                $table->unsignedBigInteger('usuario_apertura_id')->nullable();
                $table->timestamp('fecha_apertura')->nullable();
                $table->timestamp('fecha_cierre')->nullable();
                $table->string('estado')->default('abierta');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (!Schema::hasTable('caja_movimientos')) {
            Schema::create('caja_movimientos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('caja_id');
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->string('tipo');
                $table->decimal('monto', 10, 2)->default(0);
                $table->string('descripcion')->nullable();
                $table->string('referencia')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    /* =========================================================
     |  Helpers
     * ========================================================= */

    private function escenario(string $mpOrderId = 'ORD-TEST-1'): array
    {
        $cred = MercadoPagoCredencial::create([
            'restaurante_id'   => self::RESTAURANTE_ID,
            'mp_user_id'       => '999',
            'access_token'     => 'TEST-ACCESS-TOKEN',
            'refresh_token'    => 'TEST-REFRESH-TOKEN',
            'live_mode'        => false,
            'connected_at'     => now(),
        ]);

        $caja = Caja::create([
            'restaurante_id' => self::RESTAURANTE_ID,
            'estado'         => 'abierta',
            'fecha_apertura' => now(),
        ]);

        $orden = Orden::create([
            'restaurante_id'         => self::RESTAURANTE_ID,
            'usuario_id'             => 5,
            'total'                  => 250.00,
            'propina'                => 0,
            'estado'                 => 'ENTREGADA',
            'metodo_pago'            => 'mercadopago',
            'mercadopago_order_id'   => $mpOrderId,
        ]);

        return [$cred, $caja, $orden];
    }

    private function fakeOrder(string $mpOrderId, string $status, ?string $paymentStatus, ?string $paymentId = null): void
    {
        Http::fake([
            "api.mercadopago.com/v1/orders/{$mpOrderId}" => Http::response([
                'id'     => $mpOrderId,
                'status' => $status,
                'transactions' => [
                    'payments' => [[
                        'id'     => $paymentId,
                        'status' => $paymentStatus,
                    ]],
                ],
            ], 200),
        ]);
    }

    /* =========================================================
     |  Pruebas
     * ========================================================= */

    public function test_pago_aprobado_cierra_orden_y_registra_ingreso_una_sola_vez(): void
    {
        [$cred, $caja, $orden] = $this->escenario();
        $this->fakeOrder('ORD-TEST-1', 'processed', 'approved', 'PAY-1');

        $payload = ['type' => 'order', 'data' => ['id' => 'ORD-TEST-1']];

        $this->postJson('/api/mercadopago/point/webhook', $payload)
            ->assertStatus(200)
            ->assertJson(['ok' => true]);

        $orden->refresh();
        $this->assertSame('CERRADA', $orden->estado);
        $this->assertSame('mercadopago', $orden->metodo_pago);
        $this->assertSame('PAY-1', $orden->mercadopago_payment_id);

        $this->assertSame(1, CajaMovimientos::where('caja_id', $caja->id)->count());
        $movimiento = CajaMovimientos::first();
        $this->assertSame('ingreso', $movimiento->tipo);
        $this->assertEquals(250.00, (float) $movimiento->monto);
        $this->assertSame('PAY-1', $movimiento->referencia);

        // Idempotencia: reenviar el mismo webhook no duplica el ingreso ni la venta.
        $this->postJson('/api/mercadopago/point/webhook', $payload)->assertStatus(200);

        $this->assertSame(1, CajaMovimientos::where('caja_id', $caja->id)->count());
        $this->assertSame('CERRADA', $orden->fresh()->estado);
    }

    public function test_no_cierra_la_orden_si_el_pago_no_esta_aprobado(): void
    {
        [$cred, $caja, $orden] = $this->escenario();
        $this->fakeOrder('ORD-TEST-1', 'at_terminal', 'pending');

        $this->postJson('/api/mercadopago/point/webhook', [
            'type' => 'order',
            'data' => ['id' => 'ORD-TEST-1'],
        ])->assertStatus(200);

        $this->assertSame('ENTREGADA', $orden->fresh()->estado);
        $this->assertSame(0, CajaMovimientos::count());
    }

    public function test_webhook_de_orden_desconocida_no_falla(): void
    {
        Http::fake();

        $this->postJson('/api/mercadopago/point/webhook', [
            'type' => 'order',
            'data' => ['id' => 'ORD-INEXISTENTE'],
        ])->assertStatus(200)->assertJson(['ok' => true]);

        $this->assertSame(0, CajaMovimientos::count());
    }

    public function test_webhook_con_firma_valida_procesa_el_pago(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto-test']);

        [$cred, $caja, $orden] = $this->escenario();
        $this->fakeOrder('ORD-TEST-1', 'processed', 'approved', 'PAY-1');

        $ts     = '1700000000';
        $reqId  = 'req-123';
        $dataId = 'ORD-TEST-1';
        $hash   = hash_hmac('sha256', "id:{$dataId};request-id:{$reqId};ts:{$ts};", 'secreto-test');

        $this->postJson('/api/mercadopago/point/webhook', [
            'type' => 'order',
            'data' => ['id' => $dataId],
        ], [
            'x-signature'  => "ts={$ts},v1={$hash}",
            'x-request-id' => $reqId,
        ])->assertStatus(200);

        $this->assertSame('CERRADA', $orden->fresh()->estado);
    }

    public function test_webhook_con_firma_invalida_se_ignora(): void
    {
        config(['services.mercadopago.webhook_secret' => 'secreto-test']);

        [$cred, $caja, $orden] = $this->escenario();
        $this->fakeOrder('ORD-TEST-1', 'processed', 'approved', 'PAY-1');

        $this->postJson('/api/mercadopago/point/webhook', [
            'type' => 'order',
            'data' => ['id' => 'ORD-TEST-1'],
        ], [
            'x-signature'  => 'ts=1700000000,v1=firma-incorrecta',
            'x-request-id' => 'req-123',
        ])->assertStatus(200);

        $this->assertSame('ENTREGADA', $orden->fresh()->estado);
        $this->assertSame(0, CajaMovimientos::count());
    }
}
