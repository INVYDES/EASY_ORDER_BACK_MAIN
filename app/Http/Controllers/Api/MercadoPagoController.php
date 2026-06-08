<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            $user        = $request->user();
            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json(['success' => false, 'message' => 'Usuario sin propietario asociado'], 403);
            }

            $licencia = Licencia::findOrFail($licenciaId);

            // FIX: usar fecha_expiracion (campo real del modelo, no vence_en)
            $yaActiva = PropietarioLicencia::where('propietario_id', $propietario->id)
                ->where('licencia_id', $licenciaId)
                ->where('estado', 'ACTIVA')
                ->where('fecha_expiracion', '>', now())
                ->exists();

            if ($yaActiva) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya tienes esta licencia activa'
                ], 409);
            }

            $preferenceClient = new PreferenceClient();

            $preferenceData = [
                'items' => [[
                    'title'       => $licencia->nombre,
                    'quantity'    => 1,
                    'unit_price'  => (float) $licencia->precio,
                    'currency_id' => 'MXN',
                ]],
                'back_urls' => [
                    'success' => env('FRONTEND_URL') . '/licencia-exito',
                    'failure' => env('FRONTEND_URL') . '/licencia-error',
                    'pending' => env('FRONTEND_URL') . '/licencia-pendiente',
                ],
                'auto_return'      => 'approved',
                'notification_url' => env('APP_URL') . '/api/mercadopago/webhook',
                'external_reference' => 'LIC-' . $licencia->id . '-' . $propietario->id,
                'payer' => ['email' => $user->email],
            ];

            $preference = $preferenceClient->create($preferenceData);

            // FIX: Solo campos que existen en $fillable del modelo
            PropietarioLicencia::create([
                'propietario_id' => $propietario->id,
                'licencia_id'    => $licencia->id,
                'estado'         => 'PENDIENTE',
                'metodo_pago'    => 'mercadopago',
                'fecha_inicio'   => now(),
            ]);

            return response()->json([
                'success'    => true,
                'init_point' => $preference->init_point,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Licencia no encontrada'], 404);
        } catch (\Exception $e) {
            \Log::error('MP Licencia error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al crear pago'], 500);
        }
    }

    // ============================================
    // WEBHOOK MERCADOPAGO
    // ============================================

    public function webhook(Request $request)
    {
        try {
            if (!$this->validarFirmaWebhook($request)) {
                \Log::warning('Webhook MP: Firma inválida', [
                    'ip'          => $request->ip(),
                    'x-signature' => $request->header('x-signature'),
                ]);
                return response()->json(['ok' => true]);
            }

            $payload = $request->all();
            \Log::info('Webhook MP recibido', ['type' => $payload['type'] ?? 'desconocido']);

            if (($payload['type'] ?? null) !== 'payment') {
                return response()->json(['ok' => true]);
            }

            $paymentId = $payload['data']['id'] ?? null;
            if (!$paymentId) {
                return response()->json(['ok' => true]);
            }

            // Idempotencia — mercadopago_payment_id sí existe en el modelo
            $yaProcessado = PropietarioLicencia::where('mercadopago_payment_id', $paymentId)
                ->where('estado', 'ACTIVA')
                ->exists();

            if ($yaProcessado) {
                \Log::info('Webhook MP: Payment ya procesado', ['payment_id' => $paymentId]);
                return response()->json(['ok' => true]);
            }

            $paymentClient = new PaymentClient();
            $payment       = $paymentClient->get($paymentId);

            if (!$payment || $payment->status !== 'approved') {
                \Log::info('Webhook MP: Pago no aprobado', ['status' => $payment->status ?? 'nulo']);
                return response()->json(['ok' => true]);
            }

            $reference = $payment->external_reference ?? '';

            if (str_starts_with($reference, 'LIC-')) {
                $parts = explode('-', $reference);

                if (count($parts) < 3) {
                    \Log::warning('Webhook MP: Referencia inválida', ['reference' => $reference]);
                    return response()->json(['ok' => true]);
                }

                $licenciaId    = (int) $parts[1];
                $propietarioId = (int) $parts[2];

                $licencia = Licencia::find($licenciaId);
                if (!$licencia) {
                    \Log::warning('Webhook MP: Licencia no encontrada', ['licencia_id' => $licenciaId]);
                    return response()->json(['ok' => true]);
                }

                // FIX: duracion_dias con fallback por tipo
                $duracionDias    = $licencia->duracion_dias
                    ?? ($licencia->tipo === 'ANUAL' ? 365 : 30);
                $fechaExpiracion = now()->addDays($duracionDias);

                DB::beginTransaction();

                $propLicencia = PropietarioLicencia::where('propietario_id', $propietarioId)
                    ->where('licencia_id', $licenciaId)
                    ->where('estado', 'PENDIENTE')
                    ->latest()
                    ->first();

                // Datos comunes para update o create
                $datosActivacion = [
                    'estado'                 => 'ACTIVA',
                    'mercadopago_payment_id' => $paymentId,
                    'ultimo_pago_at'         => now(),
                    'proximo_pago_at'        => $fechaExpiracion,
                    'fecha_inicio'           => now(),
                    'fecha_expiracion'       => $fechaExpiracion, // campo real del modelo
                    'monto_pagado'           => $payment->transaction_amount ?? $licencia->precio,
                ];

                if ($propLicencia) {
                    $propLicencia->update($datosActivacion);
                    \Log::info('Webhook MP: Licencia activada', [
                        'prop_licencia_id' => $propLicencia->id,
                        'payment_id'       => $paymentId,
                        'fecha_expiracion' => $fechaExpiracion,
                    ]);
                } else {
                    PropietarioLicencia::create(array_merge($datosActivacion, [
                        'propietario_id' => $propietarioId,
                        'licencia_id'    => $licenciaId,
                        'metodo_pago'    => 'mercadopago',
                    ]));
                    \Log::info('Webhook MP: Licencia activada sin PENDIENTE previo', [
                        'propietario_id' => $propietarioId,
                        'licencia_id'    => $licenciaId,
                        'payment_id'     => $paymentId,
                    ]);
                }

                DB::commit();
            }

            return response()->json(['ok' => true]);

        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $response = $e->getApiResponse();
            \Log::error('MP API ERROR', [
                'status'  => $response->getStatusCode(),
                'content' => $response->getContent(),
            ]);
            return response()->json(['ok' => true]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('MP Webhook Exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return response()->json(['ok' => true]);
        }
    }

    // ============================================
    // ESTADO DE LICENCIA
    // ============================================

    public function estadoLicencia(Request $request)
    {
        try {
            $user        = $request->user();
            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json(['success' => false, 'message' => 'Sin propietario asociado'], 403);
            }

            // FIX: fecha_expiracion + accessors del modelo (dias_restantes, estado_texto)
            $propLicencia = PropietarioLicencia::with('licencia')
                ->where('propietario_id', $propietario->id)
                ->where('estado', 'ACTIVA')
                ->where('fecha_expiracion', '>', now())
                ->latest('ultimo_pago_at')
                ->first();

            if (!$propLicencia) {
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'tiene_licencia' => false,
                        'mensaje'        => 'Sin licencia activa',
                    ],
                ]);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'tiene_licencia' => true,
                    'licencia'       => [
                        'id'               => $propLicencia->licencia->id,
                        'nombre'           => $propLicencia->licencia->nombre,
                        'fecha_expiracion' => $propLicencia->fecha_expiracion,
                        'dias_restantes'   => $propLicencia->dias_restantes,   // accessor del modelo
                        'ultimo_pago'      => $propLicencia->ultimo_pago_at,
                        'proximo_pago'     => $propLicencia->proximo_pago_at,
                        'auto_renovar'     => $propLicencia->auto_renovar,
                        'estado_texto'     => $propLicencia->estado_texto,     // accessor del modelo
                        'por_vencer'       => $propLicencia->estaPorVencer(7), // método del modelo
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Estado licencia error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al consultar licencia'], 500);
        }
    }

    // ============================================
    // CREAR PREFERENCIA (pago único — para CajaController)
    // ============================================

    public function crearPreferencia(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para crear órdenes'], 403);
            }

            $request->validate([
                'orden_id' => 'required|exists:ordenes,id',
                'total'    => 'required|numeric|min:0.01',
                'items'    => 'required|array|min:1',
                'items.*.name' => 'required|string',
                'items.*.quantity' => 'required|numeric|min:1',
                'items.*.unit_amount' => 'required|numeric|min:0',
            ]);

            $preferenceClient = new PreferenceClient();

            $items = collect($request->items)->map(fn($item) => [
                'id'          => (string) ($item['id'] ?? ''),
                'title'       => $item['name'],
                'quantity'    => (int) $item['quantity'],
                'unit_price'  => (float) $item['unit_amount'],
                'currency_id' => 'MXN',
            ])->toArray();

            $preferenceData = [
                'items'             => $items,
                'external_reference' => 'ORD-' . $request->orden_id,
                'back_urls' => [
                    'success' => env('APP_URL') . '/api/caja/mercadopago/retorno',
                    'failure' => env('FRONTEND_URL') . '/pago-error',
                    'pending' => env('FRONTEND_URL') . '/pago-pendiente',
                ],
                'auto_return'      => 'approved',
                'notification_url' => env('APP_URL') . '/api/mercadopago/webhook',
                'statement_descriptor' => 'Easy Order',
            ];

            $preference = $preferenceClient->create($preferenceData);

            return response()->json([
                'success'       => true,
                'init_point'    => $preference->init_point,
                'preference_id' => $preference->id,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\MercadoPago\Exceptions\MPApiException $e) {
            $response = $e->getApiResponse();
            \Log::error('MP crearPreferencia API error', [
                'status'  => $response->getStatusCode(),
                'content' => $response->getContent(),
            ]);
            return response()->json(['success' => false, 'message' => 'Error al crear preferencia en Mercado Pago'], 500);
        } catch (\Exception $e) {
            \Log::error('MP crearPreferencia error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Error al procesar el pago'], 500);
        }
    }

    // ============================================
    // RETORNO PAGO MP (callback desde web después de pago aprobado)
    // ============================================

    public function retornoPago(Request $request)
    {
        $preferenceId = $request->query('preference_id');
        $paymentId    = $request->query('payment_id');
        $status       = $request->query('status');

        if ($status !== 'approved' || !$preferenceId) {
            return redirect()->to(env('FRONTEND_URL') . '/pago-error');
        }

        try {
            $orden = \App\Models\Orden::where('mercadopago_preference_id', $preferenceId)->first();

            if (!$orden) {
                \Log::warning('MP retorno: orden no encontrada', ['preference_id' => $preferenceId]);
                return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=orden_no_encontrada');
            }

            $restauranteActivo = $orden->restaurante;
            $caja = \App\Models\Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', now()->format('Y-m-d'))
                ->whereNull('fecha_cierre')
                ->first();

            if (!$caja) {
                return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=caja_cerrada');
            }

            DB::beginTransaction();

            $orden->estado      = 'CERRADA';
            $orden->metodo_pago = 'mercadopago';
            $orden->save();

            \App\Models\CajaMovimientos::create([
                'caja_id'     => $caja->id,
                'usuario_id'  => $orden->usuario_id,
                'tipo'        => 'ingreso',
                'monto'       => $orden->total,
                'descripcion' => 'Pago Mercado Pago - Orden #' . $orden->id,
                'referencia'  => $paymentId,
            ]);

            DB::commit();

            try {
                \Illuminate\Support\Facades\Broadcast::event(new \App\Events\CajaActualizada('venta', $restauranteActivo->id, [
                    'orden_id' => $orden->id,
                    'monto'    => (float) $orden->total,
                    'metodo'   => 'mercadopago',
                ]));
            } catch (\Exception $be) {
                \Log::warning('Broadcast pago MP: ' . $be->getMessage());
            }

            return redirect()->to(env('FRONTEND_URL') . '/pago-exitoso?orden=' . $orden->id);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error retorno MP: ' . $e->getMessage());
            return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=generic_error');
        }
    }

    // ============================================
    // HELPER: VALIDAR FIRMA DEL WEBHOOK
    // ============================================

    private function validarFirmaWebhook(Request $request): bool
    {
        $secret = env('MERCADOPAGO_WEBHOOK_SECRET');

        if (!$secret) {
            \Log::warning('Webhook MP: MERCADOPAGO_WEBHOOK_SECRET no configurado — validación omitida');
            return true;
        }

        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');
        $dataId     = $request->query('data.id') ?? $request->input('data.id');

        if (!$xSignature || !$xRequestId || !$dataId) {
            return false;
        }

        $ts   = null;
        $hash = null;
        foreach (explode(',', $xSignature) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === 'ts') $ts   = trim($val);
            if ($key === 'v1') $hash = trim($val);
        }

        if (!$ts || !$hash) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $hash);
    }
}