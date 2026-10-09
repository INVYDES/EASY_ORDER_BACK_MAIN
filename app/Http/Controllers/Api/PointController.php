<?php

namespace App\Http\Controllers\Api;

use App\Events\CajaActualizada;
use App\Events\OrdenActualizada;
use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Models\CajaMovimientos;
use App\Models\MercadoPagoCredencial;
use App\Models\MercadoPagoTerminal;
use App\Models\Orden;
use App\Scopes\TenantScope;
use App\Services\MercadoPagoPointService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cobros con terminales Mercado Pago Point (Orders API).
 *
 * La caja llama a `crearOrden`; Mercado Pago carga el cobro en el terminal y
 * confirma por webhook. El webhook cierra la orden y registra el ingreso en la
 * caja (igual que un pago normal).
 */
class PointController extends Controller
{
    public function __construct(private MercadoPagoPointService $point)
    {
    }

    /* =========================================================
     |  Helpers
     * ========================================================= */

    private function credencial(): ?MercadoPagoCredencial
    {
        return MercadoPagoCredencial::first();
    }

    private function restauranteId(): ?int
    {
        if (app()->bound('restaurante_activo')) {
            $r = app('restaurante_activo');
            if ($r) {
                return is_object($r) ? (int) $r->id : (int) $r;
            }
        }

        return auth()->user()?->restaurante_activo ? (int) auth()->user()->restaurante_activo : null;
    }

    private function sinTenantCredencialPorRestaurante(int $restauranteId): ?MercadoPagoCredencial
    {
        return MercadoPagoCredencial::withoutGlobalScope(TenantScope::class)
            ->where('restaurante_id', $restauranteId)
            ->first();
    }

    /* =========================================================
     |  Terminales
     * ========================================================= */

    /**
     * GET /api/caja/mercadopago/point/terminales
     * Consulta las terminales en Mercado Pago y las sincroniza localmente.
     */
    public function terminales(Request $request)
    {
        $cred = $this->credencial();

        if (!$cred) {
            return response()->json([
                'success' => false,
                'message' => 'Primero conecta tu cuenta de Mercado Pago.',
            ], 409);
        }

        $restauranteId = $this->restauranteId();

        try {
            $terminals = $this->point->listTerminals($cred, [
                'store_id' => $request->query('store_id'),
                'pos_id'   => $request->query('pos_id'),
            ]);
        } catch (\Throwable $e) {
            Log::error('MP Point terminales: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudieron consultar las terminales en Mercado Pago.',
            ], 502);
        }

        $resultado = [];

        foreach ($terminals as $t) {
            if (empty($t['id'])) {
                continue;
            }

            $terminal = MercadoPagoTerminal::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                [
                    'restaurante_id' => $restauranteId,
                    'terminal_id'    => $t['id'],
                ],
                [
                    'store_id'       => $t['store_id'] ?? null,
                    'pos_id'         => $t['pos_id'] ?? null,
                    'operating_mode' => $t['operating_mode'] ?? 'PDV',
                    'last_sync_at'   => now(),
                ]
            );

            $resultado[] = $terminal;
        }

        return response()->json(['success' => true, 'data' => $resultado]);
    }

    /**
     * POST /api/caja/mercadopago/point/terminales
     * Registra/edita una terminal manualmente (alias, ticket).
     */
    public function registrarTerminal(Request $request)
    {
        $data = $request->validate([
            'terminal_id'       => 'required|string|max:120',
            'alias'             => 'nullable|string|max:80',
            'store_id'          => 'nullable|string|max:40',
            'pos_id'            => 'nullable|string|max:40',
            'print_on_terminal' => 'nullable|in:seller_ticket,no_ticket',
            'is_active'         => 'nullable|boolean',
        ]);

        $terminal = MercadoPagoTerminal::updateOrCreate(
            [
                'restaurante_id' => $this->restauranteId(),
                'terminal_id'    => $data['terminal_id'],
            ],
            [
                'alias'             => $data['alias'] ?? null,
                'store_id'          => $data['store_id'] ?? null,
                'pos_id'            => $data['pos_id'] ?? null,
                'print_on_terminal' => $data['print_on_terminal'] ?? 'seller_ticket',
                'is_active'         => $data['is_active'] ?? true,
            ]
        );

        return response()->json(['success' => true, 'data' => $terminal]);
    }

    /**
     * DELETE /api/caja/mercadopago/point/terminales/{id}
     */
    public function eliminarTerminal($id)
    {
        $terminal = MercadoPagoTerminal::findOrFail($id);
        $terminal->delete();

        return response()->json(['success' => true, 'message' => 'Terminal eliminada']);
    }

    /* =========================================================
     |  Cobro
     * ========================================================= */

    /**
     * POST /api/caja/mercadopago/point/crear
     * Envía el importe de la orden a la terminal seleccionada.
     */
    public function crearOrden(Request $request)
    {
        $request->validate([
            'orden_id'          => 'required|exists:ordenes,id',
            'terminal_id'       => 'nullable|string',
            'print_on_terminal' => 'nullable|in:seller_ticket,no_ticket',
            'expiration_time'   => 'nullable|string',
            'propina'           => 'nullable|numeric|min:0',
        ]);

        $cred = $this->credencial();
        if (!$cred) {
            return response()->json([
                'success' => false,
                'message' => 'Primero conecta tu cuenta de Mercado Pago.',
            ], 409);
        }

        $orden = Orden::findOrFail($request->orden_id);

        if ($orden->estado === 'CERRADA') {
            return response()->json(['success' => false, 'message' => 'La orden ya está cobrada.'], 409);
        }

        // Terminal: la enviada o la activa por defecto.
        $terminalId = $request->terminal_id
            ?: MercadoPagoTerminal::where('is_active', true)->value('terminal_id');

        if (!$terminalId) {
            return response()->json([
                'success' => false,
                'message' => 'No hay una terminal Point configurada para esta sucursal.',
            ], 422);
        }

        // Propina capturada en caja: se guarda antes de cobrar para que el monto
        // enviado al terminal y el registrado al cerrar coincidan.
        if ($request->filled('propina')) {
            $orden->propina = (float) $request->input('propina');
            $orden->recalcularTotal();
            $orden->refresh();
        }

        $monto = round((float) $orden->total, 2);

        if ($monto <= 0) {
            return response()->json(['success' => false, 'message' => 'El monto de la orden es inválido.'], 422);
        }

        $printOnTerminal = $request->print_on_terminal
            ?: (MercadoPagoTerminal::where('terminal_id', $terminalId)->value('print_on_terminal') ?: 'seller_ticket');

        $externalReference = 'ORD-' . $orden->id . '-' . now()->timestamp; // único, sin datos personales

        $payload = [
            'type'               => 'point',
            'external_reference' => $externalReference,
            'expiration_time'    => $request->expiration_time ?: 'PT5M',
            'description'        => 'Orden ' . $orden->folio,
            'transactions'       => [
                'payments' => [
                    ['amount' => number_format($monto, 2, '.', '')],
                ],
            ],
            'config' => [
                'point' => [
                    'terminal_id'       => $terminalId,
                    'print_on_terminal' => $printOnTerminal,
                ],
            ],
        ];

        try {
            $mpOrder = $this->point->createOrder($cred, $payload);
        } catch (\Throwable $e) {
            Log::error('MP Point crearOrden: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'No se pudo enviar el cobro a la terminal. Verifica la conexión y el terminal.',
            ], 502);
        }

        $mpOrderId = $mpOrder['id'] ?? null;
        $paymentId = $mpOrder['transactions']['payments'][0]['id'] ?? null;

        $orden->update([
            'metodo_pago'            => 'mercadopago',
            'mercadopago_order_id'   => $mpOrderId,
            'mercadopago_payment_id' => $paymentId,
            'mercadopago_terminal_id'=> $terminalId,
        ]);

        return response()->json([
            'success' => true,
            'data'    => [
                'order_id'        => $mpOrderId,
                'payment_id'      => $paymentId,
                'status'          => $mpOrder['status'] ?? 'created',
                'terminal_id'     => $terminalId,
                'amount'          => $monto,
                'external_reference' => $externalReference,
            ],
        ]);
    }

    /**
     * GET /api/caja/mercadopago/point/orden/{orderId}
     * Estado del cobro; si ya está aprobado, cierra la orden.
     */
    public function estadoOrden(Request $request, $orderId)
    {
        $cred = $this->credencial();
        if (!$cred) {
            return response()->json(['success' => false, 'message' => 'Sin conexión a Mercado Pago.'], 409);
        }

        try {
            $mpOrder = $this->point->getOrder($cred, $orderId);
        } catch (\Throwable $e) {
            Log::error('MP Point estadoOrden: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo consultar el cobro.'], 502);
        }

        $status        = $mpOrder['status'] ?? null;
        $payment       = $mpOrder['transactions']['payments'][0] ?? [];
        $paymentStatus = $payment['status'] ?? null;
        $paymentId     = $payment['id'] ?? null;

        $orden = Orden::where('mercadopago_order_id', $orderId)->first();

        $cerrada = false;
        if ($orden && $status === 'processed' && $paymentStatus === 'approved') {
            $cerrada = $this->finalizarVenta($orden, $paymentId, $orderId);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'order_id'       => $orderId,
                'status'         => $status,
                'status_detail'  => $mpOrder['status_detail'] ?? null,
                'payment_status' => $paymentStatus,
                'payment_id'     => $paymentId,
                'orden_id'       => $orden?->id,
                'orden_estado'   => $orden?->estado,
                'cerrada'        => $cerrada,
            ],
        ]);
    }

    /**
     * POST /api/caja/mercadopago/point/orden/{orderId}/cancelar
     */
    public function cancelarOrden(Request $request, $orderId)
    {
        $cred = $this->credencial();
        if (!$cred) {
            return response()->json(['success' => false, 'message' => 'Sin conexión a Mercado Pago.'], 409);
        }

        try {
            $result = $this->point->cancelOrder($cred, $orderId);
        } catch (\Throwable $e) {
            Log::error('MP Point cancelarOrden: ' . $e->getMessage());

            return response()->json(['success' => false, 'message' => 'No se pudo cancelar el cobro.'], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Cancelación solicitada. Se confirmará por webhook si el terminal ya la había tomado.',
            'data'    => $result,
        ]);
    }

    /* =========================================================
     |  Webhook (ruta pública)
     * ========================================================= */

    /**
     * POST /api/mercadopago/point/webhook
     */
    public function webhook(Request $request)
    {
        if (!$this->validarFirmaWebhook($request)) {
            Log::warning('MP Point webhook: firma inválida', ['ip' => $request->ip()]);

            return response()->json(['ok' => true]);
        }

        try {
            $payload = $request->all();
            $type    = $payload['type'] ?? $payload['topic'] ?? null;

            Log::info('MP Point webhook recibido', ['type' => $type, 'data' => $payload['data'] ?? null]);

            if (!in_array($type, ['order', 'payment'], true)) {
                return response()->json(['ok' => true]);
            }

            $mpOrderId = $payload['data']['id'] ?? null;
            if (!$mpOrderId) {
                return response()->json(['ok' => true]);
            }

            $orden = Orden::withoutGlobalScope(TenantScope::class)
                ->where('mercadopago_order_id', $mpOrderId)
                ->first();

            if (!$orden) {
                Log::info('MP Point webhook: orden no encontrada', ['mp_order' => $mpOrderId]);

                return response()->json(['ok' => true]);
            }

            // Idempotencia: ya cobrada.
            if ($orden->estado === 'CERRADA') {
                return response()->json(['ok' => true]);
            }

            $cred = $this->sinTenantCredencialPorRestaurante((int) $orden->restaurante_id);
            if (!$cred) {
                Log::warning('MP Point webhook: sin credencial', ['restaurante_id' => $orden->restaurante_id]);

                return response()->json(['ok' => true]);
            }

            // Nunca confiar solo en el payload: confirmar con la API.
            try {
                $mpOrder = $this->point->getOrder($cred, $mpOrderId);
            } catch (\Throwable $e) {
                Log::error('MP Point webhook getOrder: ' . $e->getMessage());

                return response()->json(['ok' => true]);
            }

            $status        = $mpOrder['status'] ?? null;
            $payment       = $mpOrder['transactions']['payments'][0] ?? [];
            $paymentStatus = $payment['status'] ?? null;
            $paymentId     = $payment['id'] ?? null;

            if ($status === 'processed' && $paymentStatus === 'approved') {
                $this->finalizarVenta($orden, $paymentId, $mpOrderId);
            } elseif (in_array($status, ['canceled', 'expired', 'failed'], true)) {
                if ($orden->estado !== 'CERRADA') {
                    $orden->update(['mercadopago_payment_id' => null]);
                }
                Log::info('MP Point webhook: cobro no concretado', ['mp_order' => $mpOrderId, 'status' => $status]);
            }

            return response()->json(['ok' => true]);
        } catch (\Throwable $e) {
            Log::error('MP Point webhook exception', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            // 200 para que Mercado Pago no reintente en bucle.
            return response()->json(['ok' => true]);
        }
    }

    /* =========================================================
     |  Cierre de venta (idempotente)
     * ========================================================= */

    /**
     * Cierra la orden, registra el ingreso en la caja abierta y notifica por Reverb.
     */
    private function finalizarVenta(Orden $orden, ?string $paymentId, ?string $mpOrderId): bool
    {
        if ($orden->estado === 'CERRADA') {
            return false;
        }

        $restauranteId = (int) $orden->restaurante_id;

        DB::beginTransaction();

        try {
            $orden->estado      = 'CERRADA';
            $orden->metodo_pago = 'mercadopago';
            if ($paymentId) {
                $orden->mercadopago_payment_id = $paymentId;
            }
            $orden->save();

            $caja = Caja::withoutGlobalScope(TenantScope::class)
                ->where('restaurante_id', $restauranteId)
                ->whereNull('fecha_cierre')
                ->latest()
                ->first();

            if ($caja) {
                CajaMovimientos::create([
                    'caja_id'     => $caja->id,
                    'usuario_id'  => $orden->usuario_id,
                    'tipo'        => 'ingreso',
                    'monto'       => (float) $orden->total,
                    'descripcion' => 'Pago terminal Point - Orden #' . $orden->id,
                    'referencia'  => $paymentId ?: $mpOrderId,
                ]);
            } else {
                Log::warning('MP Point: no hay caja abierta al cerrar la venta', [
                    'restaurante_id' => $restauranteId,
                    'orden_id'       => $orden->id,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('MP Point finalizarVenta error: ' . $e->getMessage());

            return false;
        }

        // Notificaciones fuera de la transacción.
        try {
            broadcast(new CajaActualizada('venta', $restauranteId, [
                'orden_id' => $orden->id,
                'monto'    => (float) $orden->total,
                'metodo'   => 'mercadopago',
            ]));
        } catch (\Throwable $e) {
            Log::warning('MP Point broadcast caja: ' . $e->getMessage());
        }

        try {
            broadcast(new OrdenActualizada($orden->fresh(), 'cerrada', $restauranteId));
        } catch (\Throwable $e) {
            Log::warning('MP Point broadcast orden: ' . $e->getMessage());
        }

        Log::info('MP Point: venta cerrada', [
            'orden_id'   => $orden->id,
            'mp_order'   => $mpOrderId,
            'payment_id' => $paymentId,
        ]);

        return true;
    }

    /* =========================================================
     |  Firma del webhook
     * ========================================================= */

    private function validarFirmaWebhook(Request $request): bool
    {
        $secret = config('services.mercadopago.webhook_secret');

        if (!$secret) {
            Log::warning('MP Point webhook: MERCADOPAGO_WEBHOOK_SECRET no configurado — validación omitida');

            return true;
        }

        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');
        $dataId     = $request->query('data.id') ?? $request->input('data.id');

        if (!$xSignature || !$xRequestId || !$dataId) {
            return false;
        }

        $ts = null;
        $hash = null;

        foreach (explode(',', $xSignature) as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, null);
            if ($key === 'ts') {
                $ts = trim((string) $val);
            }
            if ($key === 'v1') {
                $hash = trim((string) $val);
            }
        }

        if (!$ts || !$hash) {
            return false;
        }

        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $hash);
    }
}
