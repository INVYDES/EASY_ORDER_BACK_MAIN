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

   public function createSubscription(Request $request)
{
    $accessToken = $this->getAccessToken();

    $request->validate([
        'plan_id' => 'required|string'
    ]);

    $response = Http::withToken($accessToken)
        ->post('https://api-m.sandbox.paypal.com/v1/billing/subscriptions', [
            'plan_id' => $request->plan_id,
            'application_context' => [
                'return_url' => route('paypal.success'),
                'cancel_url' => route('paypal.cancel')
            ]
        ]);

    $data = $response->json();

    $approvalUrl = collect($data['links'])
        ->firstWhere('rel', 'approve')['href'];

    return response()->json([
        'approval_url' => $approvalUrl
    ]);
}

   public function success(Request $request)
{
    $subscriptionId = $request->query('subscription_id');

    // 🔥 AQUÍ activas licencia en tu BD
    // Ejemplo:
    // LicenciaUsuario::create([...]);

    return redirect()->to(env('FRONTEND_URL') . '/suscripcion-activa');
}

 
}