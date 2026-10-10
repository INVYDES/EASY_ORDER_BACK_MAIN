<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MercadoPagoCredencial;
use App\Models\MercadoPagoTerminal;
use App\Services\MercadoPagoPointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Schema;

/**
 * Conexión y desconexión de la cuenta de Mercado Pago de cada restaurante.
 *
 * Cada restaurante autoriza su propia cuenta (OAuth de terceros), de modo que
 * las ventas con Point caen directamente en su cuenta de Mercado Pago.
 */
class PointOAuthController extends Controller
{
    public function __construct(private MercadoPagoPointService $point)
    {
    }

    private function restauranteId(Request $request): ?int
    {
        if (app()->bound('restaurante_activo')) {
            $restaurante = app('restaurante_activo');
            if ($restaurante) {
                return is_object($restaurante) ? (int) $restaurante->id : (int) $restaurante;
            }
        }

        return $request->user()?->restaurante_activo ? (int) $request->user()->restaurante_activo : null;
    }

    /**
     * GET /api/caja/mercadopago/oauth/conectar
     * Devuelve la URL a la que hay que mandar al dueño para autorizar.
     */
    public function conectar(Request $request)
    {
        $restauranteId = $this->restauranteId($request);

        if (!$restauranteId) {
            return response()->json(['success' => false, 'message' => 'No hay restaurante activo'], 403);
        }

        if (!config('services.mercadopago.app_id') || !config('services.mercadopago.client_secret')) {
            return response()->json([
                'success' => false,
                'message' => 'Mercado Pago no está configurado en el servidor (MERCADOPAGO_APP_ID / MERCADOPAGO_CLIENT_SECRET).',
            ], 500);
        }

        return response()->json([
            'success'           => true,
            'authorization_url' => $this->point->authorizationUrl($restauranteId),
        ]);
    }

    /**
     * GET /api/mercadopago/oauth/callback  (ruta pública)
     * Mercado Pago regresa aquí con el `code`; lo cambiamos por el token.
     */
    public function callback(Request $request)
    {
        $code     = $request->query('code');
        $state    = $request->query('state');
        $frontend = rtrim(env('FRONTEND_URL', config('app.url')), '/');
        $destino  = $frontend . '/panel/caja';

        if (!$code || !$state) {
            return redirect()->to($destino . '?mp=error&motivo=faltan_parametros');
        }

        $restauranteId = $this->point->resolveState((string) $state);

        if (!$restauranteId) {
            return redirect()->to($destino . '?mp=error&motivo=state_invalido');
        }

        try {
            $cred = $this->point->exchangeCode((string) $code, $restauranteId);

            Log::info('MP Point: cuenta conectada', [
                'restaurante_id' => $restauranteId,
                'mp_user_id'     => $cred->mp_user_id,
                'live_mode'      => $cred->live_mode,
            ]);

            return redirect()->to($destino . '?mp=ok');
        } catch (\Throwable $e) {
            Log::error('MP Point callback error: ' . $e->getMessage());

            return redirect()->to($destino . '?mp=error&motivo=token');
        }
    }

    /**
     * GET /api/caja/mercadopago/oauth/estado
     */
    public function estado(Request $request)
    {
        try {
            if (!Schema::hasTable('mercadopago_credenciales')) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'conectado'    => false,
                        'vigente'      => false,
                        'mp_user_id'   => null,
                        'live_mode'    => false,
                        'connected_at' => null,
                        'terminales'   => [],
                    ],
                ]);
            }

            $cred = MercadoPagoCredencial::first();
            $terminales = Schema::hasTable('mercadopago_terminales')
                ? MercadoPagoTerminal::where('is_active', true)->orderBy('alias')->get()
                : [];

            return response()->json([
                'success' => true,
                'data'    => [
                    'conectado'    => (bool) $cred,
                    'vigente'      => $cred ? $cred->estaVigente() : false,
                    'mp_user_id'   => $cred?->mp_user_id,
                    'live_mode'    => (bool) ($cred?->live_mode ?? false),
                    'connected_at' => $cred?->connected_at,
                    'terminales'   => $terminales,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('MP Point estado error: ' . $e->getMessage());
            return response()->json([
                'success' => true,
                'data'    => [
                    'conectado'    => false,
                    'vigente'      => false,
                    'mp_user_id'   => null,
                    'live_mode'    => false,
                    'connected_at' => null,
                    'terminales'   => [],
                ],
            ]);
        }
    }

    /**
     * POST /api/caja/mercadopago/oauth/desconectar
     */
    public function desconectar(Request $request)
    {
        try {
            if (Schema::hasTable('mercadopago_credenciales')) {
                MercadoPagoCredencial::query()->delete();
            }
            if (Schema::hasTable('mercadopago_terminales')) {
                MercadoPagoTerminal::query()->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('MP Point desconectar error: ' . $e->getMessage());
        }

        Log::info('MP Point: cuenta desconectada', [
            'user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Cuenta de Mercado Pago desconectada',
        ]);
    }
}
