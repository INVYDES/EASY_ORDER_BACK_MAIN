<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use App\Models\Licencia;
use App\Models\PropietarioLicencia;

class PayPalController extends Controller
{
    // ============================================
    // HELPER: ACCESS TOKEN (con caché de 8 horas)
    // FIX: Evita una llamada extra a PayPal en cada operación
    // ============================================

    private function getAccessToken(): string
    {
        return Cache::remember('paypal_access_token', 3600 * 8, function () {
            $response = Http::withBasicAuth(
                config('services.paypal.client_id'),
                config('services.paypal.secret')
            )->asForm()->post(config('services.paypal.base_url') . '/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

            if (!$response->successful()) {
                throw new \Exception('Error al obtener token de PayPal: ' . $response->body());
            }

            return $response->json()['access_token'];
        });
    }

    // ============================================
    // CREAR SUSCRIPCIÓN
    // ============================================

    public function createSubscription(Request $request)
    {
        $request->validate([
            'licencia_id' => 'required|integer|exists:licencias,id',
        ]);

        try {
            $user        = $request->user();
            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json(['success' => false, 'error' => 'Sin propietario asociado'], 403);
            }

            $licencia = Licencia::findOrFail($request->licencia_id);

            if (!$licencia->paypal_plan_id) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Esta licencia no tiene plan de PayPal configurado',
                ], 400);
            }

            // FIX: Verificar si ya tiene licencia activa
            $yaActiva = PropietarioLicencia::where('propietario_id', $propietario->id)
                ->where('licencia_id', $licencia->id)
                ->where('estado', 'ACTIVA')
                ->where('vence_en', '>', now())
                ->exists();

            if ($yaActiva) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Ya tienes esta licencia activa',
                ], 409);
            }

            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->post(config('services.paypal.base_url') . '/v1/billing/subscriptions', [
                    'plan_id' => $licencia->paypal_plan_id,

                    // FIX: custom_id guarda userId-licenciaId para recuperarlo en el callback
                    'custom_id' => $user->id . '-' . $licencia->id . '-' . $propietario->id,

                    'application_context' => [
                        'return_url'  => route('paypal.success'),
                        'cancel_url'  => route('paypal.cancel'),
                        'brand_name'  => config('app.name'),
                        'user_action' => 'SUBSCRIBE_NOW',
                    ],
                ]);

            if (!$response->successful()) {
                \Log::error('PayPal createSubscription error', ['response' => $response->json()]);
                return response()->json([
                    'success' => false,
                    'error'   => 'Error al crear suscripción en PayPal',
                ], 500);
            }

            $data        = $response->json();
            $approvalUrl = collect($data['links'])->firstWhere('rel', 'approve')['href'] ?? null;

            if (!$approvalUrl) {
                return response()->json(['success' => false, 'error' => 'No se obtuvo URL de aprobación'], 500);
            }

            // Guardar intento PENDIENTE
            PropietarioLicencia::create([
                'propietario_id'      => $propietario->id,
                'licencia_id'         => $licencia->id,
                'estado'              => 'PENDIENTE',
                'paypal_suscripcion_id' => $data['id'],
            ]);

            return response()->json([
                'success'      => true,
                'approval_url' => $approvalUrl,
                'suscripcion_id' => $data['id'],
            ]);

        } catch (\Exception $e) {
            \Log::error('PayPal createSubscription exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'error' => 'Error al procesar suscripción'], 500);
        }
    }

    // ============================================
    // CALLBACK: SUSCRIPCIÓN APROBADA
    // FIX: Recuperar usuario desde custom_id en lugar de session
    // ============================================

    public function success(Request $request)
    {
        $subscriptionId = $request->query('subscription_id');

        if (!$subscriptionId) {
            return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=sin_suscripcion');
        }

        try {
            $accessToken = $this->getAccessToken();

            // Obtener detalles de la suscripción para leer custom_id
            $response = Http::withToken($accessToken)
                ->get(config('services.paypal.base_url') . '/v1/billing/subscriptions/' . $subscriptionId);

            if (!$response->successful()) {
                \Log::error('PayPal success: no se pudo obtener suscripción', ['id' => $subscriptionId]);
                return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=error_verificacion');
            }

            $data     = $response->json();
            $customId = $data['custom_id'] ?? null;

            if (!$customId) {
                \Log::error('PayPal success: custom_id vacío', ['subscription_id' => $subscriptionId]);
                return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=referencia_invalida');
            }

            // FIX: custom_id tiene formato userId-licenciaId-propietarioId
            $parts = explode('-', $customId);
            if (count($parts) < 3) {
                return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=referencia_invalida');
            }

            [$userId, $licenciaId, $propietarioId] = $parts;

            $licencia = Licencia::find($licenciaId);
            if (!$licencia) {
                return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=licencia_no_encontrada');
            }

            DB::beginTransaction();

            $duracionDias = $licencia->duracion_dias ?? 30;

            // Buscar el PENDIENTE y activarlo
            $propLicencia = PropietarioLicencia::where('propietario_id', $propietarioId)
                ->where('licencia_id', $licenciaId)
                ->where('estado', 'PENDIENTE')
                ->latest()
                ->first();

            if ($propLicencia) {
                $propLicencia->update([
                    'estado'                => 'ACTIVA',
                    'paypal_suscripcion_id' => $subscriptionId,
                    'ultimo_pago_at'        => now(),
                    'vence_en'              => now()->addDays($duracionDias),
                ]);
            } else {
                // Crear directamente si no hay PENDIENTE
                PropietarioLicencia::create([
                    'propietario_id'        => $propietarioId,
                    'licencia_id'           => $licenciaId,
                    'estado'                => 'ACTIVA',
                    'paypal_suscripcion_id' => $subscriptionId,
                    'ultimo_pago_at'        => now(),
                    'vence_en'              => now()->addDays($duracionDias),
                ]);
            }

            DB::commit();

            \Log::info('PayPal: Suscripción activada', [
                'subscription_id' => $subscriptionId,
                'propietario_id'  => $propietarioId,
                'licencia_id'     => $licenciaId,
            ]);

            return redirect(env('FRONTEND_URL') . '/licencia-exito?suscripcion=' . $subscriptionId);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('PayPal success error: ' . $e->getMessage());
            return redirect(env('FRONTEND_URL') . '/licencia-error?motivo=error_activacion');
        }
    }

    // ============================================
    // CALLBACK: SUSCRIPCIÓN CANCELADA
    // ============================================

    public function cancel()
    {
        return redirect(env('FRONTEND_URL') . '/licencia-cancelada');
    }

    // ============================================
    // WEBHOOK PAYPAL — RENOVACIONES Y EVENTOS
    // FIX: Sin esto, las renovaciones mensuales no se registran
    // ============================================

    public function webhook(Request $request)
    {
        try {
            // Validar que el webhook viene de PayPal
            if (!$this->validarWebhookPayPal($request)) {
                \Log::warning('PayPal Webhook: Firma inválida', ['ip' => $request->ip()]);
                return response()->json(['ok' => false], 401);
            }

            $payload   = $request->all();
            $eventType = $payload['event_type'] ?? null;

            \Log::info('PayPal Webhook recibido', ['event_type' => $eventType]);

            match ($eventType) {
                // Pago de renovación procesado exitosamente
                'PAYMENT.SALE.COMPLETED'             => $this->handleRenovacion($payload),
                // Suscripción cancelada por el usuario o por falta de pago
                'BILLING.SUBSCRIPTION.CANCELLED'     => $this->handleCancelacion($payload),
                // Suscripción suspendida (pago fallido)
                'BILLING.SUBSCRIPTION.SUSPENDED'     => $this->handleSuspension($payload),
                // Suscripción reactivada
                'BILLING.SUBSCRIPTION.RE-ACTIVATED'  => $this->handleReactivacion($payload),
                default => \Log::info('PayPal Webhook: Evento no manejado', ['event_type' => $eventType]),
            };

            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            \Log::error('PayPal Webhook Exception: ' . $e->getMessage());
            return response()->json(['ok' => true]); // 200 para que PayPal no reintente
        }
    }

    // ── Handlers de eventos ──────────────────────────────────────────────────

    private function handleRenovacion(array $payload): void
    {
        $subscriptionId = $payload['resource']['billing_agreement_id']
            ?? $payload['resource']['id']
            ?? null;

        if (!$subscriptionId) return;

        $propLicencia = PropietarioLicencia::where('paypal_suscripcion_id', $subscriptionId)
            ->where('estado', 'ACTIVA')
            ->first();

        if (!$propLicencia) return;

        $duracionDias = $propLicencia->licencia->duracion_dias ?? 30;

        // Extender vencimiento desde la fecha actual de vencimiento
        $nuevaFecha = ($propLicencia->vence_en && $propLicencia->vence_en > now())
            ? $propLicencia->vence_en->addDays($duracionDias)
            : now()->addDays($duracionDias);

        $propLicencia->update([
            'ultimo_pago_at' => now(),
            'vence_en'       => $nuevaFecha,
        ]);

        \Log::info('PayPal: Licencia renovada', [
            'prop_licencia_id' => $propLicencia->id,
            'nueva_fecha'      => $nuevaFecha,
        ]);
    }

    private function handleCancelacion(array $payload): void
    {
        $subscriptionId = $payload['resource']['id'] ?? null;
        if (!$subscriptionId) return;

        PropietarioLicencia::where('paypal_suscripcion_id', $subscriptionId)
            ->update(['estado' => 'CANCELADA']);

        \Log::info('PayPal: Suscripción cancelada', ['subscription_id' => $subscriptionId]);
    }

    private function handleSuspension(array $payload): void
    {
        $subscriptionId = $payload['resource']['id'] ?? null;
        if (!$subscriptionId) return;

        PropietarioLicencia::where('paypal_suscripcion_id', $subscriptionId)
            ->update(['estado' => 'SUSPENDIDA']);

        \Log::info('PayPal: Suscripción suspendida', ['subscription_id' => $subscriptionId]);
    }

    private function handleReactivacion(array $payload): void
    {
        $subscriptionId = $payload['resource']['id'] ?? null;
        if (!$subscriptionId) return;

        $propLicencia = PropietarioLicencia::where('paypal_suscripcion_id', $subscriptionId)->first();
        if (!$propLicencia) return;

        $duracionDias = $propLicencia->licencia->duracion_dias ?? 30;

        $propLicencia->update([
            'estado'         => 'ACTIVA',
            'ultimo_pago_at' => now(),
            'vence_en'       => now()->addDays($duracionDias),
        ]);

        \Log::info('PayPal: Suscripción reactivada', ['subscription_id' => $subscriptionId]);
    }

    // ── Helper: Validar firma del webhook de PayPal ──────────────────────────

    private function validarWebhookPayPal(Request $request): bool
    {
        $webhookId = env('PAYPAL_WEBHOOK_ID');

        // Sin webhook ID configurado, omitir validación (solo en desarrollo)
        if (!$webhookId) {
            \Log::warning('PayPal Webhook: PAYPAL_WEBHOOK_ID no configurado — validación omitida');
            return true;
        }

        try {
            $accessToken = $this->getAccessToken();

            // PayPal ofrece un endpoint para verificar la firma del webhook
            $response = Http::withToken($accessToken)
                ->post(config('services.paypal.base_url') . '/v1/notifications/verify-webhook-signature', [
                    'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
                    'cert_url'          => $request->header('PAYPAL-CERT-URL'),
                    'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
                    'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
                    'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
                    'webhook_id'        => $webhookId,
                    'webhook_event'     => $request->all(),
                ]);

            return $response->successful()
                && ($response->json()['verification_status'] ?? '') === 'SUCCESS';

        } catch (\Exception $e) {
            \Log::error('PayPal validación webhook error: ' . $e->getMessage());
            return false;
        }
    }

    // ============================================
    // CREAR ORDEN (pago único — para CajaController)
    // ============================================

    public function createOrder(Request $request)
    {
        try {
            $accessToken = $this->getAccessToken();

            $items = collect($request->items)->map(fn($item) => [
                'name'        => $item['name'] ?? 'Producto',
                'quantity'    => (string) ($item['quantity'] ?? 1),
                'unit_amount' => [
                    'currency_code' => 'MXN',
                    'value'         => number_format((float) ($item['unit_amount'] ?? 0), 2, '.', ''),
                ],
            ])->toArray();

            $response = Http::withToken($accessToken)
                ->post(config('services.paypal.base_url') . '/v2/checkout/orders', [
                    'intent'         => 'CAPTURE',
                    'purchase_units' => [[
                        'custom_id' => (string) $request->order_id,
                        'amount'    => [
                            'currency_code' => 'MXN',
                            'value'         => number_format((float) $request->total, 2, '.', ''),
                        ],
                        'items' => $items,
                    ]],
                    'application_context' => [
                        'return_url' => env('APP_URL') . '/api/caja/paypal/capturar',
                        'cancel_url' => env('FRONTEND_URL') . '/pago-cancelado',
                    ],
                ]);

            if (!$response->successful()) {
                \Log::error('PayPal createOrder error', ['response' => $response->json()]);
                return response()->json(['success' => false, 'message' => 'Error al crear orden en PayPal'], 500);
            }

            $data        = $response->json();
            $approvalUrl = collect($data['links'])->firstWhere('rel', 'approve')['href'] ?? null;

            return response()->json([
                'success'      => true,
                'order_id'     => $data['id'],
                'approval_url' => $approvalUrl,
            ]);

        } catch (\Exception $e) {
            \Log::error('PayPal createOrder exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al crear pago'], 500);
        }
    }

    // ============================================
    // CAPTURAR ORDEN (usado por CajaController)
    // ============================================

    public function captureOrder(Request $request)
    {
        try {
            $token       = $request->query('token');
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->post(config('services.paypal.base_url') . "/v2/checkout/orders/{$token}/capture");

            if (!$response->successful()) {
                throw new \Exception('Error al capturar orden PayPal');
            }

            return response()->json(['success' => true, 'data' => $response->json()]);

        } catch (\Exception $e) {
            \Log::error('PayPal captureOrder error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al capturar pago'], 500);
        }
    }
}