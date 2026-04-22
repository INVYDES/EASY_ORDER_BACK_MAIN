<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Caja;
use App\Models\Orden;
use App\Models\CajaMovimientos;
use Illuminate\Support\Facades\DB;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoController extends Controller
{
    public function __construct()
    {
        MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
    }

    /**
     * Crear preferencia de pago para una orden
     */
    public function crearPreferencia(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $request->validate([
                'orden_id' => 'required|exists:ordenes,id',
                'total' => 'required|numeric|min:0.01',
                'items' => 'required|array|min:1'
            ]);

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('id', $request->orden_id)
                ->where('restaurante_id', $restauranteActivo->id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta orden ya está cerrada'
                ], 400);
            }

            // Verificar que la caja esté abierta
            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', now()->format('Y-m-d'))
                ->whereNull('fecha_cierre')
                ->first();

            if (!$caja) {
                return response()->json([
                    'success' => false,
                    'message' => 'No hay una caja abierta'
                ], 400);
            }

            // Crear preferencia de pago
            $preferenceClient = new PreferenceClient();
            
            $items = [];
            foreach ($request->items as $item) {
                $items[] = [
                    'id' => $item['id'] ?? null,
                    'title' => $item['nombre'],
                    'quantity' => (int) $item['cantidad'],
                    'unit_price' => (float) $item['precio'],
                    'currency_id' => 'MXN'
                ];
            }

            $preferenceData = [
                'items' => $items,
                'back_urls' => [
                    'success' => env('FRONTEND_URL') . '/pago-exitoso',
                    'failure' => env('FRONTEND_URL') . '/pago-error',
                    'pending' => env('FRONTEND_URL') . '/pago-pendiente'
                ],
                'auto_return' => 'approved',
                'notification_url' => env('APP_URL') . '/api/mercadopago/webhook',
                'external_reference' => (string) $orden->id,
                'statement_descriptor' => 'Pedido #' . $orden->id,
                'payer' => [
                    'email' => $request->user()->email ?? 'cliente@ejemplo.com'
                ]
            ];

            $preference = $preferenceClient->create($preferenceData);

            // Guardar preference_id en la orden
            $orden->mercadopago_preference_id = $preference->id;
            $orden->save();

            return response()->json([
                'success' => true,
                'preference_id' => $preference->id,
                'init_point' => $preference->init_point
            ]);

        } catch (\Exception $e) {
            \Log::error('Error crear preferencia MP: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear preferencia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook para notificaciones de Mercado Pago
     */
    public function webhook(Request $request)
    {
        try {
            $payload = $request->all();
            \Log::info('Webhook MP recibido', $payload);

            // Verificar tipo de notificación
            if (isset($payload['type']) && $payload['type'] === 'payment') {
                $paymentId = $payload['data']['id'];
                
                // Obtener información del pago
                $paymentClient = new PaymentClient();
                $payment = $paymentClient->get($paymentId);

                if ($payment && $payment->status === 'approved') {
                    $ordenId = $payment->external_reference;
                    
                    $orden = Orden::find($ordenId);
                    
                    if ($orden && $orden->estado !== 'CERRADA') {
                        DB::beginTransaction();
                        
                        // Actualizar orden
                        $orden->estado = 'CERRADA';
                        $orden->metodo_pago = 'mercadopago';
                        $orden->mercadopago_payment_id = $paymentId;
                        $orden->save();

                        // Registrar en caja
                        $caja = Caja::where('restaurante_id', $orden->restaurante_id)
                            ->whereDate('fecha_apertura', now()->format('Y-m-d'))
                            ->whereNull('fecha_cierre')
                            ->first();

                        if ($caja) {
                            CajaMovimientos::create([
                                'caja_id' => $caja->id,
                                'usuario_id' => $orden->usuario_id,
                                'tipo' => 'ingreso',
                                'monto' => $orden->total,
                                'descripcion' => 'Pago Mercado Pago - Orden #' . $orden->id,
                                'referencia' => $paymentId
                            ]);
                        }
                        
                        DB::commit();

                        // Broadcast
                        try {
                            broadcast(new \App\Events\CajaActualizada('venta', $orden->restaurante_id, [
                                'orden_id' => $orden->id,
                                'monto' => (float) $orden->total,
                                'metodo' => 'mercadopago'
                            ]));
                        } catch (\Exception $be) {
                            \Log::warning('Broadcast MP: ' . $be->getMessage());
                        }
                    }
                }
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            \Log::error('Error webhook MP: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }
}