<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PayPalController extends Controller
{
    // Obtener token de acceso
    private function getAccessToken()
    {
        $clientId = env('PAYPAL_CLIENT_ID');
        $clientSecret = env('PAYPAL_CLIENT_SECRET');

        $response = Http::withBasicAuth($clientId, $clientSecret)
            ->asForm()
            ->post('https://api-m.sandbox.paypal.com/v1/oauth2/token', [
                'grant_type' => 'client_credentials',
            ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        throw new \Exception('Error al obtener token de PayPal');
    }

    // Crear orden de pago
    public function createOrder(Request $request)
    {
        try {
            $accessToken = $this->getAccessToken();
            
            // Validar datos del carrito
            $request->validate([
                'total' => 'required|numeric|min:0.01',
                'items' => 'required|array',
                'order_id' => 'required|integer'
            ]);

            $payload = [
                "intent" => "CAPTURE",
                "purchase_units" => [
                    [
                        "reference_id" => (string) $request->order_id,
                        "amount" => [
                            "currency_code" => env('PAYPAL_CURRENCY', 'MXN'),
                            "value" => number_format($request->total, 2, '.', ''),
                            "breakdown" => [
                                "item_total" => [
                                    "currency_code" => env('PAYPAL_CURRENCY', 'MXN'),
                                    "value" => number_format($request->total, 2, '.', '')
                                ]
                            ]
                        ],
                        "items" => array_map(function($item) {
                            return [
                                "name" => $item['nombre'],
                                "quantity" => (string) $item['cantidad'],
                                "unit_amount" => [
                                    "currency_code" => env('PAYPAL_CURRENCY', 'MXN'),
                                    "value" => number_format($item['precio'], 2, '.', '')
                                ]
                            ];
                        }, $request->items)
                    ]
                ],
                "application_context" => [
                    "return_url" => route('paypal.capture'),
                    "cancel_url" => route('paypal.cancel'),
                    "brand_name" => "Tu Restaurante",
                    "user_action" => "PAY_NOW"
                ]
            ];

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://api-m.sandbox.paypal.com/v2/checkout/orders', $payload);

            if ($response->successful()) {
                $data = $response->json();
                // Buscar el link de aprobación
                $approvalUrl = collect($data['links'])
                    ->firstWhere('rel', 'approve')['href'];

                return response()->json([
                    'success' => true,
                    'approval_url' => $approvalUrl,
                    'order_id' => $data['id']
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al crear la orden de pago'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // Capturar pago (callback después del pago)
    public function captureOrder(Request $request)
    {
        try {
            $orderId = $request->query('token');
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post("https://api-m.sandbox.paypal.com/v2/checkout/orders/{$orderId}/capture");

            if ($response->successful()) {
                $data = $response->json();
                
                // Actualizar el estado de la orden en tu base de datos
                // Orden::where('id', $data['purchase_units'][0]['reference_id'])
                //     ->update(['estado_pago' => 'COMPLETADO', 'paypal_order_id' => $orderId]);

                return redirect()->to(env('FRONTEND_URL') . '/pago-exitoso');
            }

            return redirect()->to(env('FRONTEND_URL') . '/pago-error');

        } catch (\Exception $e) {
            return redirect()->to(env('FRONTEND_URL') . '/pago-error');
        }
    }

    // Cancelar pago
    public function cancelOrder()
    {
        return redirect()->to(env('FRONTEND_URL') . '/pago-cancelado');
    }
}