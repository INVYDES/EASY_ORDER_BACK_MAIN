<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class PayPalController extends Controller
{
    private function getAccessToken()
    {
        $response = Http::withBasicAuth(
            config('services.paypal.client_id'),
            config('services.paypal.secret')
        )->asForm()->post(config('services.paypal.base_url') . '/v1/oauth2/token', [
            'grant_type' => 'client_credentials',
        ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        throw new \Exception('Error al obtener token de PayPal');
    }

    public function createSubscription(Request $request)
    {
        $request->validate([
            'licencia_id' => 'required|integer'
        ]);

        $licencia = DB::table('licencias')->where('id', $request->licencia_id)->first();

        if (!$licencia || !$licencia->paypal_plan_id) {
            return response()->json([
                'error' => 'Licencia inválida o sin plan de PayPal'
            ], 400);
        }

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->post(config('services.paypal.base_url') . '/v1/billing/subscriptions', [
                'plan_id' => $licencia->paypal_plan_id,
                'application_context' => [
                    'return_url' => route('paypal.success'),
                    'cancel_url' => route('paypal.cancel')
                ]
            ]);

        if (!$response->successful()) {
            return response()->json([
                'error' => 'Error creando suscripción',
                'paypal' => $response->json()
            ], 500);
        }

        $data = $response->json();

        $approvalUrl = collect($data['links'])
            ->firstWhere('rel', 'approve')['href'] ?? null;

        return response()->json([
            'approval_url' => $approvalUrl
        ]);
    }

    public function success(Request $request)
    {
        $subscriptionId = $request->query('subscription_id');

        if (!$subscriptionId) {
            return redirect(env('FRONTEND_URL') . '/error');
        }

        // 🔥 Guardar suscripción (mínimo viable)
        DB::table('subscriptions')->insert([
            'paypal_subscription_id' => $subscriptionId,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        return redirect(env('FRONTEND_URL') . '/suscripcion-activa');
    }

    public function cancel()
    {
        return redirect(env('FRONTEND_URL') . '/suscripcion-cancelada');
    }
}