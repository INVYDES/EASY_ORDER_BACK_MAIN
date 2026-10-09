<?php

namespace App\Services;

use App\Models\MercadoPagoCredencial;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Integración con Mercado Pago Point (terminales) vía Orders API.
 *
 * Flujo:
 *  1. OAuth: el restaurante autoriza la app y guardamos su access_token.
 *  2. Configuración: la terminal debe estar en modo PDV y asociada a un POS.
 *  3. Cobro: se crea un Order (type=point) y la terminal lo recibe.
 *  4. Confirmación: llega por webhook / consulta del estado del order.
 *
 * Docs: https://www.mercadopago.com.mx/developers/es/docs/mp-point/landing
 */
class MercadoPagoPointService
{
    private const TIMEOUT = 20;

    public function baseUrl(): string
    {
        return rtrim(config('services.mercadopago.base_url', 'https://api.mercadopago.com'), '/');
    }

    /* =========================================================
     |  OAuth (integración de terceros / marketplace)
     * ========================================================= */

    /**
     * URL a la que se redirige al dueño para conectar su cuenta de Mercado Pago.
     * El `state` va cifrado y contiene el id del restaurante.
     */
    public function authorizationUrl(int $restauranteId): string
    {
        $state = Crypt::encryptString((string) $restauranteId);

        $query = http_build_query([
            'client_id'     => config('services.mercadopago.app_id'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $state,
            'redirect_uri'  => config('services.mercadopago.redirect_uri'),
        ]);

        return rtrim(config('services.mercadopago.authorize_url'), '?&') . '?' . $query;
    }

    /**
     * Recupera el restaurante a partir del `state` cifrado. Null si es inválido.
     */
    public function resolveState(string $state): ?int
    {
        try {
            $id = (int) Crypt::decryptString($state);
            return $id > 0 ? $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Intercambia el `code` de autorización por las credenciales del restaurante.
     */
    public function exchangeCode(string $code, int $restauranteId): MercadoPagoCredencial
    {
        $response = Http::asJson()->timeout(self::TIMEOUT)
            ->post($this->baseUrl() . '/oauth/token', [
                'client_id'     => config('services.mercadopago.app_id'),
                'client_secret' => config('services.mercadopago.client_secret'),
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => config('services.mercadopago.redirect_uri'),
            ]);

        if (!$response->successful()) {
            Log::error('MP Point OAuth: fallo al intercambiar code', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('No se pudo obtener el token de Mercado Pago.');
        }

        $data = $response->json();

        return MercadoPagoCredencial::withoutGlobalScope(\App\Scopes\TenantScope::class)
            ->updateOrCreate(
                ['restaurante_id' => $restauranteId],
                [
                    'restaurante_id'   => $restauranteId,
                    'mp_user_id'       => isset($data['user_id']) ? (string) $data['user_id'] : null,
                    'access_token'     => $data['access_token'] ?? '',
                    'refresh_token'    => $data['refresh_token'] ?? null,
                    'public_key'       => $data['public_key'] ?? null,
                    'live_mode'        => (bool) ($data['live_mode'] ?? false),
                    'scope'            => $data['scope'] ?? null,
                    'token_expires_at' => isset($data['expires_in'])
                        ? now()->addSeconds((int) $data['expires_in'])
                        : null,
                    'connected_at'     => now(),
                ]
            );
    }

    /**
     * Renueva el access_token usando el refresh_token.
     */
    public function refresh(MercadoPagoCredencial $cred): void
    {
        if (!$cred->refresh_token) {
            throw new \RuntimeException('La conexión con Mercado Pago expiró. Vuelve a conectar la cuenta.');
        }

        $response = Http::asJson()->timeout(self::TIMEOUT)
            ->post($this->baseUrl() . '/oauth/token', [
                'client_id'     => config('services.mercadopago.app_id'),
                'client_secret' => config('services.mercadopago.client_secret'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $cred->refresh_token,
            ]);

        if (!$response->successful()) {
            Log::error('MP Point OAuth: fallo al renovar token', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('No se pudo renovar el token de Mercado Pago.');
        }

        $data = $response->json();

        $cred->update([
            'access_token'     => $data['access_token'] ?? $cred->access_token,
            'refresh_token'    => $data['refresh_token'] ?? $cred->refresh_token,
            'public_key'       => $data['public_key'] ?? $cred->public_key,
            'token_expires_at' => isset($data['expires_in'])
                ? now()->addSeconds((int) $data['expires_in'])
                : $cred->token_expires_at,
        ]);
    }

    /**
     * Devuelve un access_token válido, renovándolo si está por vencer.
     */
    public function accessToken(MercadoPagoCredencial $cred): string
    {
        if ($cred->token_expires_at && $cred->token_expires_at->lessThanOrEqualTo(now()->addMinutes(10))) {
            $this->refresh($cred);
            $cred->refresh();
        }

        return (string) $cred->access_token;
    }

    /* =========================================================
     |  Terminales
     * ========================================================= */

    /**
     * Lista las terminales asociadas a la cuenta del restaurante.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTerminals(MercadoPagoCredencial $cred, array $filters = []): array
    {
        $query = array_filter([
            'limit'    => $filters['limit'] ?? 50,
            'offset'   => $filters['offset'] ?? 0,
            'store_id' => $filters['store_id'] ?? null,
            'pos_id'   => $filters['pos_id'] ?? null,
        ], fn($v) => $v !== null && $v !== '');

        $response = Http::withToken($this->accessToken($cred))
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->get($this->baseUrl() . '/terminals/v1/list', $query);

        if (!$response->successful()) {
            $this->throwApiError('listTerminals', $response);
        }

        return $response->json('data.terminals', []) ?? [];
    }

    /* =========================================================
     |  Orders API (cobro en terminal)
     * ========================================================= */

    /**
     * Crea un Order tipo `point`: la terminal lo recibe automáticamente.
     *
     * @return array<string, mixed>
     */
    public function createOrder(MercadoPagoCredencial $cred, array $payload): array
    {
        $response = Http::withToken($this->accessToken($cred))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()])
            ->timeout(self::TIMEOUT)
            ->post($this->baseUrl() . '/v1/orders', $payload);

        if (!$response->successful()) {
            $this->throwApiError('createOrder', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrder(MercadoPagoCredencial $cred, string $orderId): array
    {
        $response = Http::withToken($this->accessToken($cred))
            ->acceptJson()
            ->timeout(self::TIMEOUT)
            ->get($this->baseUrl() . '/v1/orders/' . urlencode($orderId));

        if (!$response->successful()) {
            $this->throwApiError('getOrder', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * Cancela un Order. Para estados `at_terminal` MP lo procesa de forma asíncrona.
     *
     * @return array<string, mixed>
     */
    public function cancelOrder(MercadoPagoCredencial $cred, string $orderId): array
    {
        $response = Http::withToken($this->accessToken($cred))
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Idempotency-Key'        => (string) Str::uuid(),
                'x-allow-cancelable-status' => 'at_terminal',
            ])
            ->timeout(self::TIMEOUT)
            ->post($this->baseUrl() . '/v1/orders/' . urlencode($orderId) . '/cancel');

        if (!$response->successful()) {
            $this->throwApiError('cancelOrder', $response);
        }

        return $response->json() ?? [];
    }

    /**
     * Reembolsa un Order (total o parcial, hasta 90 días).
     *
     * @return array<string, mixed>
     */
    public function refundOrder(MercadoPagoCredencial $cred, string $orderId, ?float $amount = null): array
    {
        $request  = Http::withToken($this->accessToken($cred))
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-Idempotency-Key' => (string) Str::uuid()]);

        $url  = $this->baseUrl() . '/v1/orders/' . urlencode($orderId) . '/refund';
        $body = $amount !== null ? ['amount' => number_format($amount, 2, '.', '')] : [];

        $response = $request->timeout(self::TIMEOUT)->post($url, $body);

        if (!$response->successful()) {
            $this->throwApiError('refundOrder', $response);
        }

        return $response->json() ?? [];
    }

    /* =========================================================
     |  Helpers
     * ========================================================= */

    private function throwApiError(string $operation, \Illuminate\Http\Client\Response $response): void
    {
        Log::error("MP Point {$operation} error", [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        throw new \RuntimeException("Mercado Pago rechazó la operación ({$operation}).");
    }
}
