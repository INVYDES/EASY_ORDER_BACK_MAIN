<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Licencia;
use App\Models\PropietarioLicencia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class LicenciaController extends Controller
{
    public function __construct()
    {
        // Configurar Mercado Pago si hay token
        if (env('MERCADOPAGO_ACCESS_TOKEN')) {
            MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
        }
    }
   
public function disponibles(Request $request)
{
    try {
        // Verificar si el usuario está autenticado
        $isAuthenticated = auth()->check();
        
        $query = Licencia::where('activo', 1)
            ->where('tipo', '!=', 'EMPRESA');
        
        // Si NO está autenticado, mostrar SOLO la licencia de PRUEBA
        if (!$isAuthenticated) {
            $query->where('tipo', 'PRUEBA');
        }
        
        $licencias = $query->orderBy('precio', 'asc')
            ->orderBy('tipo', 'asc')
            ->get()
            ->map(function ($licencia) {
                return [
                    'id' => $licencia->id,
                    'nombre' => $licencia->nombre,
                    'tipo' => $licencia->tipo,
                    'max_restaurantes' => $licencia->max_restaurantes,
                    'max_usuarios' => $licencia->max_usuarios,
                    'precio' => $licencia->precio,
                    'precio_anual' => $licencia->precio_anual,
                    'dias_prueba' => $licencia->dias_prueba,
                    'descripcion' => $this->getDescripcionLicencia($licencia),
                    'popular' => $licencia->id === 1 || $licencia->id === 2,
                    'es_prueba' => $licencia->tipo === 'PRUEBA',
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $licencias,
            'is_authenticated' => $isAuthenticated
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al cargar las licencias',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Generar descripción amigable para cada licencia
     */
    private function getDescripcionLicencia($licencia)
    {
        if ($licencia->tipo === 'PRUEBA') {
            return "{$licencia->dias_prueba} días gratis para probar la plataforma. Ideal para comenzar.";
        }

        $periodo = $licencia->tipo === 'MENSUAL' ? 'mes' : 'año';
        $precio = $licencia->tipo === 'MENSUAL' ? $licencia->precio : $licencia->precio_anual;
        
        return "Hasta {$licencia->max_restaurantes} restaurante(s) y {$licencia->max_usuarios} usuarios por {$periodo}. Precio: \${$precio}";
    }

    // ============================================
    // CRUD DE LICENCIAS (ADMIN)
    // ============================================

    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $perPage = $request->get('per_page', 15);
            $query = Licencia::query();

            if ($request->has('nombre') && !empty($request->nombre)) {
                $query->where('nombre', 'like', '%' . $request->nombre . '%');
            }
            if ($request->has('tipo') && in_array($request->tipo, ['MENSUAL', 'ANUAL'])) {
                $query->where('tipo', $request->tipo);
            }
            if ($request->has('activo')) {
                $query->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
            }

            $licencias = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $licencias->items(),
                'pagination' => [
                    'current_page' => $licencias->currentPage(),
                    'per_page' => $licencias->perPage(),
                    'total' => $licencias->total(),
                    'last_page' => $licencias->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener licencias'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $request->validate([
                'nombre' => 'required|string|max:150|unique:licencias,nombre',
                'tipo' => 'required|in:MENSUAL,ANUAL',
                'max_restaurantes' => 'required|integer|min:1|max:4',
                'max_usuarios' => 'nullable|integer|min:1|max:999',
                'precio' => 'required|numeric|min:0',
                'activo' => 'nullable|boolean',
                'paypal_plan_id' => 'nullable|string|max:100',
                'mercadopago_plan_id' => 'nullable|string|max:100',
            ]);

            $data = $request->all();
            $data['activo'] = $request->has('activo') ? $request->activo : true;
            $data['max_usuarios'] = $request->max_usuarios ?? 5;

            $licencia = Licencia::create($data);

            return response()->json(['success' => true, 'message' => 'Licencia creada', 'data' => $licencia], 201);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al crear licencia'], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $licencia = Licencia::findOrFail($id);
            return response()->json(['success' => true, 'data' => $licencia]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Licencia no encontrada'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $licencia = Licencia::findOrFail($id);

            $request->validate([
                'nombre' => 'sometimes|string|max:150|unique:licencias,nombre,' . $id,
                'tipo' => 'sometimes|in:MENSUAL,ANUAL',
                'max_restaurantes' => 'sometimes|integer|min:1|max:4',
                'max_usuarios' => 'nullable|integer|min:1|max:999',
                'precio' => 'sometimes|numeric|min:0',
                'activo' => 'nullable|boolean',
                'paypal_plan_id' => 'nullable|string',
                'mercadopago_plan_id' => 'nullable|string',
            ]);

            $licencia->update($request->all());

            return response()->json(['success' => true, 'message' => 'Licencia actualizada', 'data' => $licencia]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar'], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ELIMINAR_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $licencia = Licencia::findOrFail($id);

            if ($licencia->propietarios()->count() > 0) {
                return response()->json(['success' => false, 'message' => 'No se puede eliminar, tiene propietarios asignados'], 409);
            }

            $licencia->delete();

            return response()->json(['success' => true, 'message' => 'Licencia eliminada']);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar'], 500);
        }
    }

    public function toggleActive(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $licencia = Licencia::findOrFail($id);
            $licencia->activo = !$licencia->activo;
            $licencia->save();

            return response()->json(['success' => true, 'message' => 'Licencia ' . ($licencia->activo ? 'activada' : 'desactivada')]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al cambiar estado'], 500);
        }
    }

    // ============================================
    // METODOS PUBLICOS (para clientes)
    // ============================================

    public function miLicencia(Request $request)
    {
        try {
            $user = $request->user();
            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
            }

            $licenciaActiva = PropietarioLicencia::with('licencia')
                ->where('propietario_id', $propietario->id)
                ->where('estado', 'ACTIVA')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$licenciaActiva) {
                return response()->json(['success' => true, 'data' => null]);
            }

            $diasRestantes = $licenciaActiva->fecha_expiracion ? max(0, Carbon::now()->diffInDays($licenciaActiva->fecha_expiracion, false)) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $licenciaActiva->id,
                    'licencia' => $licenciaActiva->licencia,
                    'estado' => $licenciaActiva->estado,
                    'fecha_inicio' => $licenciaActiva->fecha_inicio,
                    'fecha_expiracion' => $licenciaActiva->fecha_expiracion,
                    'dias_restantes' => $diasRestantes,
                    'metodo_pago' => $licenciaActiva->metodo_pago ?? 'paypal'
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener licencia'], 500);
        }
    }

    // ============================================
    // PAYPAL - SUSCRIPCIONES
    // ============================================

    private function getPayPalAccessToken()
    {
        $response = Http::withBasicAuth(env('PAYPAL_CLIENT_ID'), env('PAYPAL_CLIENT_SECRET'))
            ->asForm()
            ->post(env('PAYPAL_MODE') === 'sandbox' 
                ? 'https://api-m.sandbox.paypal.com/v1/oauth2/token'
                : 'https://api-m.paypal.com/v1/oauth2/token', [
                'grant_type' => 'client_credentials'
            ]);

        if ($response->successful()) {
            return $response->json()['access_token'];
        }

        throw new \Exception('Error al obtener token de PayPal');
    }

    private function crearProductoPayPal($licencia)
    {
        $accessToken = $this->getPayPalAccessToken();

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post(env('PAYPAL_MODE') === 'sandbox'
                ? 'https://api-m.sandbox.paypal.com/v1/catalogs/products'
                : 'https://api-m.paypal.com/v1/catalogs/products', [
                "name" => "Licencia " . $licencia->nombre,
                "description" => "Licencia de software - " . $licencia->nombre,
                "type" => "SERVICE",
                "category" => "SOFTWARE"
            ]);

        if ($response->successful()) {
            return $response->json()['id'];
        }

        throw new \Exception('Error al crear producto en PayPal');
    }

    public function crearPlanPayPal(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_LICENCIAS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $licencia = Licencia::findOrFail($request->licencia_id);
            $accessToken = $this->getPayPalAccessToken();

            $intervalUnit = $licencia->tipo === 'MENSUAL' ? 'MONTH' : 'YEAR';
            $productId = $this->crearProductoPayPal($licencia);

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(env('PAYPAL_MODE') === 'sandbox'
                    ? 'https://api-m.sandbox.paypal.com/v1/billing/plans'
                    : 'https://api-m.paypal.com/v1/billing/plans', [
                    "product_id" => $productId,
                    "name" => "Suscripción - " . $licencia->nombre,
                    "description" => "Suscripción {$licencia->tipo} - {$licencia->nombre}",
                    "status" => "ACTIVE",
                    "billing_cycles" => [[
                        "frequency" => ["interval_unit" => $intervalUnit, "interval_count" => 1],
                        "tenure_type" => "REGULAR",
                        "sequence" => 1,
                        "total_cycles" => 0,
                        "pricing_scheme" => [
                            "fixed_price" => [
                                "value" => number_format($licencia->precio, 2, '.', ''),
                                "currency_code" => "MXN"
                            ]
                        ]
                    ]],
                    "payment_preferences" => [
                        "auto_bill_outstanding" => true,
                        "setup_fee" => ["value" => "0", "currency_code" => "MXN"],
                        "setup_fee_failure_action" => "CONTINUE",
                        "payment_failure_threshold" => 3
                    ]
                ]);

            if ($response->successful()) {
                $planData = $response->json();
                $licencia->paypal_plan_id = $planData['id'];
                $licencia->save();

                return response()->json(['success' => true, 'message' => 'Plan creado', 'data' => ['plan_id' => $planData['id']]]);
            }

            return response()->json(['success' => false, 'message' => 'Error al crear plan en PayPal'], 500);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function comprarLicenciaPayPal(Request $request, $licenciaId)
    {
        try {
            $user = $request->user();
            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
            }

            $licencia = Licencia::findOrFail($licenciaId);

            if (!$licencia->activo) {
                return response()->json(['success' => false, 'message' => 'Licencia no disponible'], 400);
            }

            if (!$licencia->paypal_plan_id) {
                return response()->json(['success' => false, 'message' => 'Plan PayPal no configurado'], 400);
            }

            $licenciaActiva = PropietarioLicencia::where('propietario_id', $propietario->id)
                ->where('estado', 'ACTIVA')->where('fecha_expiracion', '>', Carbon::now())->first();

            if ($licenciaActiva) {
                return response()->json(['success' => false, 'message' => 'Ya tienes una licencia activa'], 400);
            }

            $accessToken = $this->getPayPalAccessToken();

            $response = Http::withToken($accessToken)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post(env('PAYPAL_MODE') === 'sandbox'
                    ? 'https://api-m.sandbox.paypal.com/v1/billing/subscriptions'
                    : 'https://api-m.paypal.com/v1/billing/subscriptions', [
                    "plan_id" => $licencia->paypal_plan_id,
                    "start_time" => Carbon::now()->addMinutes(5)->toIso8601String(),
                    "subscriber" => [
                        "name" => ["given_name" => $user->name, "surname" => ""],
                        "email_address" => $user->email
                    ],
                    "application_context" => [
                        "brand_name" => env('APP_NAME', 'Easy Order'),
                        "locale" => "es-MX",
                        "shipping_preference" => "NO_SHIPPING",
                        "user_action" => "SUBSCRIBE_NOW",
                        "return_url" => env('FRONTEND_URL') . '/licencias/exito',
                        "cancel_url" => env('FRONTEND_URL') . '/licencias/cancelado'
                    ]
                ]);

            if ($response->successful()) {
                $data = $response->json();

                PropietarioLicencia::create([
                    'propietario_id' => $propietario->id,
                    'licencia_id' => $licencia->id,
                    'fecha_inicio' => Carbon::now(),
                    'fecha_expiracion' => $licencia->tipo === 'MENSUAL' ? Carbon::now()->addMonth() : Carbon::now()->addYear(),
                    'estado' => 'PENDIENTE',
                    'paypal_subscription_id' => $data['id'],
                    'monto_pagado' => $licencia->precio,
                    'metodo_pago' => 'paypal'
                ]);

                $approvalUrl = collect($data['links'])->firstWhere('rel', 'approve')['href'];

                return response()->json([
                    'success' => true,
                    'approval_url' => $approvalUrl,
                    'subscription_id' => $data['id'],
                    'pasarela' => 'paypal'
                ]);
            }

            return response()->json(['success' => false, 'message' => 'Error al crear suscripción PayPal'], 500);

        } catch (\Exception $e) {
            Log::error('Error PayPal: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function webhookPayPal(Request $request)
    {
        try {
            $payload = $request->all();
            $eventType = $payload['event_type'] ?? null;

            Log::info('Webhook PayPal', ['event_type' => $eventType]);

            if ($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
                $subscriptionId = $payload['resource']['id'] ?? null;
                if ($subscriptionId) {
                    PropietarioLicencia::where('paypal_subscription_id', $subscriptionId)->update(['estado' => 'ACTIVA']);
                }
            }

            if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                $subscriptionId = $payload['resource']['id'] ?? null;
                if ($subscriptionId) {
                    PropietarioLicencia::where('paypal_subscription_id', $subscriptionId)->update(['estado' => 'CANCELADA']);
                }
            }

            return response()->json(['status' => 'success'], 200);

        } catch (\Exception $e) {
            Log::error('Error webhook PayPal: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }
// ============================================
// MERCADO PAGO — método corregido
// ============================================

public function comprarLicenciaMercadoPago(Request $request, $licenciaId)
{
    try {
        $user       = $request->user();
        $propietario = $user->propietario;

        if (!$propietario) {
            return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
        }

        $licencia = Licencia::findOrFail($licenciaId);

        if (!$licencia->activo) {
            return response()->json(['success' => false, 'message' => 'Licencia no disponible'], 400);
        }

        $licenciaActiva = PropietarioLicencia::where('propietario_id', $propietario->id)
            ->where('estado', 'ACTIVA')
            ->where('fecha_expiracion', '>', Carbon::now())
            ->first();

        if ($licenciaActiva) {
            return response()->json(['success' => false, 'message' => 'Ya tienes una licencia activa'], 400);
        }

        $preferenceClient = new PreferenceClient();

        $preference = $preferenceClient->create([
            'items' => [[
                'id'          => (string) $licencia->id,
                'title'       => $licencia->nombre . ' — Licencia ' . ucfirst(strtolower($licencia->tipo)),
                'description' => 'Licencia de software para sistema de restaurantes',
                'quantity'    => 1,
                'unit_price'  => (float) ($licencia->tipo === 'ANUAL' ? $licencia->precio_anual : $licencia->precio),
                'currency_id' => 'MXN',
            ]],
            'payer' => [
                'name'  => $user->name,
                'email' => $user->email,
            ],
            'back_urls' => [
                'success' => config('app.frontend_url') . '/licencias/exito',
                'failure' => config('app.frontend_url') . '/licencias/error',
                'pending' => config('app.frontend_url') . '/licencias/pendiente',
            ],
            'auto_return'          => 'approved',
            // ✅ Formato consistente con el webhook
            'external_reference'   => 'LIC-' . $licencia->id . '-' . $propietario->id,
            'notification_url'     => config('app.url') . '/api/licencias/webhook/mercadopago',
            'statement_descriptor' => 'Easy Order',
            'metadata' => [
                'propietario_id' => $propietario->id,
                'licencia_id'    => $licencia->id,
                'tipo'           => $licencia->tipo,
            ],
        ]);

        return response()->json([
            'success'      => true,
            'init_point'   => $preference->init_point,
            'preference_id'=> $preference->id,
            'pasarela'     => 'mercadopago',
        ]);

    } catch (\Exception $e) {
        Log::error('Error Mercado Pago: ' . $e->getMessage());
        return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// ============================================
// WEBHOOK MERCADO PAGO — corregido
// ============================================

public function webhookMercadoPago(Request $request)
{
    try {
        $payload = $request->all();
        Log::info('Webhook Mercado Pago', $payload);

        if (($payload['type'] ?? null) !== 'payment') {
            return response()->json(['status' => 'ok'], 200);
        }

        $paymentId = $payload['data']['id'];

        $client = new PaymentClient();
        $payment = $client->get($paymentId);

        if (!$payment || $payment->status !== 'approved') {
            return response()->json(['status' => 'ok'], 200);
        }

        $reference = $payment->external_reference ?? '';

        if (!str_starts_with($reference, 'LIC-')) {
            Log::warning('Referencia desconocida', ['ref' => $reference]);
            return response()->json(['status' => 'ok'], 200);
        }

        $parts = explode('-', $reference);

        if (count($parts) !== 3) {
            Log::error('Formato inválido', ['ref' => $reference]);
            return response()->json(['status' => 'error'], 400);
        }

        [, $licenciaId, $propietarioId] = $parts;

        $licencia = Licencia::find($licenciaId);

        if (!$licencia) {
            Log::error('Licencia no encontrada', ['licencia_id' => $licenciaId]);
            return response()->json(['status' => 'ok'], 200);
        }

        // 🔒 Buscar registro existente
        $registro = PropietarioLicencia::where('propietario_id', $propietarioId)
            ->where('licencia_id', $licenciaId)
            ->latest()
            ->first();

        // 🔁 Evitar duplicados
        if ($registro && $registro->estado === 'ACTIVA') {
            return response()->json(['status' => 'ok'], 200);
        }

        $dias = $licencia->tipo === 'ANUAL' ? 365 : 30;

        if (!$registro) {
            $registro = new PropietarioLicencia();
            $registro->propietario_id = $propietarioId;
            $registro->licencia_id = $licenciaId;
        }

        $registro->estado = 'ACTIVA';
        $registro->fecha_inicio = now();
        $registro->fecha_expiracion = now()->addDays($dias);
        $registro->monto_pagado = $payment->transaction_amount;
        $registro->metodo_pago = 'mercadopago';
        $registro->mercadopago_payment_id = $paymentId;

        $registro->save();

        Log::info('Licencia activada', [
            'propietario_id' => $propietarioId,
            'licencia_id' => $licenciaId
        ]);

        return response()->json(['status' => 'ok'], 200);

    } catch (\Exception $e) {
        Log::error('Error webhook MP: ' . $e->getMessage());
        return response()->json(['status' => 'error'], 500);
    }
}

    public function comprarLicencia(Request $request, $licenciaId)
{
    $pasarela = $request->input('pasarela', 'paypal');
    
    if ($pasarela === 'mercadopago') {
        return $this->comprarLicenciaMercadoPago($request, $licenciaId);
    }
    
    return $this->comprarLicenciaPayPal($request, $licenciaId);
}
}