<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Licencia;
use App\Models\PropietarioLicencia;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoController extends Controller
{
    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
    }

    // ============================================
    // COMPRAR LICENCIA
    // ============================================

    public function comprarLicencia(Request $request, $licenciaId)
    {
        try {
            $user = $request->user();
            $propietario = $user->propietario;

            $licencia = Licencia::findOrFail($licenciaId);

            $preferenceClient = new PreferenceClient();

            $preferenceData = [
                'items' => [[
                    'title' => $licencia->nombre,
                    'quantity' => 1,
                    'unit_price' => (float) $licencia->precio,
                    'currency_id' => 'MXN'
                ]],
                'back_urls' => [
                    'success' => env('FRONTEND_URL') . '/licencia-exito',
                    'failure' => env('FRONTEND_URL') . '/licencia-error',
                    'pending' => env('FRONTEND_URL') . '/licencia-pendiente'
                ],
                'auto_return' => 'approved',
                'notification_url' => env('APP_URL') . '/api/mercadopago/webhook',

                // 🔑 IMPORTANTE: identificar licencia, no orden
                'external_reference' => 'LIC-' . $licencia->id . '-' . $propietario->id,

                'payer' => [
                    'email' => $user->email
                ]
            ];

            $preference = $preferenceClient->create($preferenceData);

            // Guardar intento de compra
            PropietarioLicencia::create([
                'propietario_id' => $propietario->id,
                'licencia_id' => $licencia->id,
                'estado' => 'PENDIENTE',
                'mp_preference_id' => $preference->id
            ]);

            return response()->json([
                'success' => true,
                'init_point' => $preference->init_point
            ]);

        } catch (\Exception $e) {
            \Log::error('MP Licencia error: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error al crear pago'
            ], 500);
        }
    }

    // ============================================
    // WEBHOOK MERCADOPAGO
    // ============================================

    public function webhook(Request $request)
    {
        try {
            $payload = $request->all();
            \Log::info('Webhook MP', $payload);

            if (($payload['type'] ?? null) !== 'payment') {
                return response()->json(['ok' => true]);
            }

            $paymentId = $payload['data']['id'];

            $paymentClient = new PaymentClient();
            $payment = $paymentClient->get($paymentId);

            if (!$payment || $payment->status !== 'approved') {
                return response()->json(['ok' => true]);
            }

            // 🔑 Leer referencia
            $reference = $payment->external_reference;

            if (str_starts_with($reference, 'LIC-')) {

                [$prefix, $licenciaId, $propietarioId] = explode('-', $reference);

                PropietarioLicencia::where('propietario_id', $propietarioId)
                    ->where('licencia_id', $licenciaId)
                    ->where('estado', 'PENDIENTE')
                    ->update([
                        'estado' => 'ACTIVA',
                        'mp_payment_id' => $paymentId
                    ]);
            }

            return response()->json(['ok' => true]);

        } catch (\MercadoPago\Exceptions\MPApiException $e) {

    $response = $e->getApiResponse();

    \Log::error('MP ERROR', [
        'status' => $response->getStatusCode(),
        'content' => $response->getContent()
    ]);

    return response()->json([
        'success' => false,
        'error' => $response->getContent()
    ], 500);
}
    }
}