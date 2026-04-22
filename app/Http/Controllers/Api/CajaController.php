<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Caja;
use App\Models\CajaMovimientos;
use App\Models\Orden;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Broadcast;

class CajaController extends Controller
{
    // ── Helper: selectRaw unificado de ventas por método de pago ──────────────
    // Agrupa efectivo, tarjeta, transferencia, paypal y mercadopago.
    // Cualquier otro método cae en "otros".
    private function ventasSelectRaw(): string
    {
        return '
            SUM(CASE WHEN metodo_pago = "efectivo"       THEN total ELSE 0 END) as ventas_efectivo,
            SUM(CASE WHEN metodo_pago = "tarjeta"        THEN total ELSE 0 END) as ventas_tarjeta,
            SUM(CASE WHEN metodo_pago = "transferencia"  THEN total ELSE 0 END) as ventas_transferencia,
            SUM(CASE WHEN metodo_pago = "paypal"         THEN total ELSE 0 END) as ventas_paypal,
            SUM(CASE WHEN metodo_pago = "mercadopago"    THEN total ELSE 0 END) as ventas_mercadopago,
            SUM(CASE WHEN metodo_pago NOT IN (
                "efectivo","tarjeta","transferencia","paypal","mercadopago"
            ) THEN total ELSE 0 END) as ventas_otros,
            COUNT(*) as total_ordenes
        ';
    }

    // ── Helper: array de ventas formateado ────────────────────────────────────
    private function formatVentas($ventas): array
    {
        return [
            'efectivo'      => (float) ($ventas->ventas_efectivo ?? 0),
            'tarjeta'       => (float) ($ventas->ventas_tarjeta ?? 0),
            'transferencia' => (float) ($ventas->ventas_transferencia ?? 0),
            'paypal'        => (float) ($ventas->ventas_paypal ?? 0),
            'mercadopago'   => (float) ($ventas->ventas_mercadopago ?? 0),
            'otros'         => (float) ($ventas->ventas_otros ?? 0),
            'total_ordenes' => (int)   ($ventas->total_ordenes ?? 0),
        ];
    }

    // ── Helper: total ventas de todos los métodos ─────────────────────────────
    private function totalVentas($ventas): float
    {
        return (float) (
            ($ventas->ventas_efectivo ?? 0) +
            ($ventas->ventas_tarjeta ?? 0) +
            ($ventas->ventas_transferencia ?? 0) +
            ($ventas->ventas_paypal ?? 0) +
            ($ventas->ventas_mercadopago ?? 0) +
            ($ventas->ventas_otros ?? 0)
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ESTADO
    // ═════════════════════════════════════════════════════════════════════════
    public function estado(Request $request)
    {
        try {
            // Sin restricción de permiso — cualquier rol autenticado puede ver
            // si la caja está abierta (mesero necesita saberlo para cobrar)
            $restauranteActivo = app('restaurante_activo');
            $hoy = now()->format('Y-m-d');

            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', $hoy)
                ->whereNull('fecha_cierre')
                ->first();

            if (!$caja) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'is_open'          => false,
                        'opening_amount'   => 0,
                        'cash_in_register' => 0,
                        'daily_sales'      => 0,
                        'card_sales'       => 0,
                        'transfer_sales'   => 0,
                        'paypal_sales'     => 0,
                        'mercadopago_sales'   => 0,
                        'other_sales'      => 0,
                        'movements_count'  => 0,
                    ],
                ]);
            }

            $ventas = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)
                ->where('estado', 'CERRADA')
                ->selectRaw($this->ventasSelectRaw())
                ->first();

            $movimientos   = CajaMovimientos::where('caja_id', $caja->id)->orderByDesc('created_at')->get();
            $TotalIngresos = $movimientos->where('tipo', 'ingreso')->sum('monto');
            $TotalEgresos  = $movimientos->where('tipo', 'egreso')->sum('monto');

            $v = $this->formatVentas($ventas);

            return response()->json([
                'success' => true,
                'data' => [
                    'is_open'          => true,
                    'caja_id'          => $caja->id,
                    'opening_amount'   => (float) $caja->monto_inicial,
                    // Efectivo real en caja = apertura + ventas efectivo + ingresos manuales − egresos manuales
                    'cash_in_register' => (float) ($caja->monto_inicial + $v['efectivo'] + $TotalIngresos - $TotalEgresos),
                    'daily_sales'      => $v['efectivo'],
                    'card_sales'       => $v['tarjeta'],
                    'transfer_sales'   => $v['transferencia'],
                    'paypal_sales'     => $v['paypal'],
                    'mercadopago_sales'   => $v['mercadopago'],
                    'other_sales'      => $v['otros'],
                    'total_orders'     => $v['total_ordenes'],
                    'movements_count'  => $movimientos->count(),
                    'opened_at'        => $caja->fecha_apertura,
                    'opened_by'        => $caja->usuarioApertura
                        ? ['id' => $caja->usuarioApertura->id, 'name' => $caja->usuarioApertura->name]
                        : null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error al obtener estado','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ABRIR
    // ═════════════════════════════════════════════════════════════════════════
    public function abrir(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ABRIR_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso para abrir caja'], 403);
            }

            $request->validate(['monto_inicial' => 'required|numeric|min:0']);

            $restauranteActivo = app('restaurante_activo');
            $hoy = now()->format('Y-m-d');

            if (Caja::where('restaurante_id', $restauranteActivo->id)->whereDate('fecha_apertura', $hoy)->whereNull('fecha_cierre')->exists()) {
                return response()->json(['success'=>false,'message'=>'Ya hay una caja abierta para hoy'], 409);
            }

            DB::beginTransaction();

            $caja = Caja::create([
                'restaurante_id'      => $restauranteActivo->id,
                'usuario_apertura_id' => $user->id,
                'fecha_apertura'      => now(),
                'monto_inicial'       => $request->monto_inicial,
                'estado'              => 'abierta',
            ]);

            CajaMovimientos::create([
                'caja_id'     => $caja->id,
                'usuario_id'  => $user->id,
                'tipo'        => 'apertura',
                'monto'       => $request->monto_inicial,
                'descripcion' => 'Apertura de caja',
                'referencia'  => 'APERTURA-' . $caja->id,
            ]);

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('ABRIR_CAJA', 'cajas', $caja->id, "Caja abierta con \${$request->monto_inicial}");
            }

            try {
                Broadcast::event(new \App\Events\CajaActualizada('abierta', $restauranteActivo->id, ['caja_id' => $caja->id]));
            } catch (\Exception $be) { \Log::warning('Broadcast abrir caja: '.$be->getMessage()); }

            return response()->json([
                'success' => true,
                'message' => 'Caja abierta correctamente',
                'data'    => ['caja_id'=>$caja->id, 'monto_inicial'=>(float)$caja->monto_inicial, 'fecha_apertura'=>$caja->fecha_apertura],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success'=>false,'message'=>'Error de validación','errors'=>$e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success'=>false,'message'=>'Error al abrir caja','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CERRAR
    // ═════════════════════════════════════════════════════════════════════════
    public function cerrar(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CERRAR_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso para cerrar caja'], 403);
            }

            $request->validate([
                'efectivo_final' => 'required|numeric|min:0',
                'observaciones'  => 'nullable|string|max:500',
            ]);

            $restauranteActivo = app('restaurante_activo');
            $hoy = now()->format('Y-m-d');

            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', $hoy)->whereNull('fecha_cierre')->first();

            if (!$caja) {
                return response()->json(['success'=>false,'message'=>'No hay una caja abierta para hoy'], 404);
            }

            DB::beginTransaction();

            $ventas = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)->where('estado','CERRADA')
                ->selectRaw($this->ventasSelectRaw())->first();

            $v = $this->formatVentas($ventas);

            $ingresos = CajaMovimientos::where('caja_id',$caja->id)->where('tipo','ingreso')->sum('monto');
            $egresos  = CajaMovimientos::where('caja_id',$caja->id)->where('tipo','egreso')->sum('monto');

            $efectivoEsperado = $caja->monto_inicial + $v['efectivo'] + $ingresos - $egresos;
            $diferencia       = $request->efectivo_final - $efectivoEsperado;

            $caja->update([
                'fecha_cierre'          => now(),
                'usuario_cierre_id'     => $user->id,
                'monto_final'           => $request->efectivo_final,
                'ventas_efectivo'       => $v['efectivo'],
                'ventas_tarjeta'        => $v['tarjeta'],
                'ventas_transferencia'  => $v['transferencia'],
                'ventas_paypal'         => $v['paypal'],
                'ventas_mercadopago'    => $v['mercadopago'],
                'total_ordenes'         => $v['total_ordenes'],
                'diferencia'            => $diferencia,
                'observaciones_cierre'  => $request->observaciones,
                'estado'                => 'cerrada',
            ]);

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('CERRAR_CAJA', 'cajas', $caja->id, "Caja cerrada. Diferencia: \${$diferencia}");
            }

            try {
                Broadcast::event(new \App\Events\CajaActualizada('cerrada', $restauranteActivo->id, ['caja_id' => $caja->id]));
            } catch (\Exception $be) { \Log::warning('Broadcast cerrar caja: '.$be->getMessage()); }

            return response()->json([
                'success' => true,
                'message' => 'Caja cerrada correctamente',
                'data'    => [
                    'caja_id'          => $caja->id,
                    'efectivo_esperado'=> (float) $efectivoEsperado,
                    'efectivo_final'   => (float) $request->efectivo_final,
                    'diferencia'       => (float) $diferencia,
                    'ventas'           => $v,
                    'total_ventas'     => $this->totalVentas($ventas),
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success'=>false,'message'=>'Error de validación','errors'=>$e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success'=>false,'message'=>'Error al cerrar caja','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // REGISTRAR MOVIMIENTO
    // ═════════════════════════════════════════════════════════════════════════
    public function registrarMovimiento(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso para registrar movimientos'], 403);
            }

            $request->validate([
                'tipo'        => 'required|in:ingreso,egreso',
                'monto'       => 'required|numeric|min:0',
                'descripcion' => 'required|string|max:255',
                'referencia'  => 'nullable|string|max:100',
            ]);

            $restauranteActivo = app('restaurante_activo');
            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', now()->format('Y-m-d'))->whereNull('fecha_cierre')->first();

            if (!$caja) {
                return response()->json(['success'=>false,'message'=>'No hay una caja abierta para hoy'], 404);
            }

            DB::beginTransaction();
            $movimiento = CajaMovimientos::create([
                'caja_id'     => $caja->id,
                'usuario_id'  => $user->id,
                'tipo'        => $request->tipo,
                'monto'       => $request->monto,
                'descripcion' => $request->descripcion,
                'referencia'  => $request->referencia,
            ]);
            DB::commit();

            try {
                Broadcast::event(new \App\Events\CajaActualizada('movimiento', $restauranteActivo->id, [
                    'tipo'  => $request->tipo,
                    'monto' => (float) $request->monto,
                ]));
            } catch (\Exception $be) { \Log::warning('Broadcast movimiento: '.$be->getMessage()); }

            return response()->json(['success'=>true,'message'=>'Movimiento registrado correctamente','data'=>$movimiento], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success'=>false,'message'=>'Error de validación','errors'=>$e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success'=>false,'message'=>'Error al registrar movimiento','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // MOVIMIENTOS (listado del día)
    // ═════════════════════════════════════════════════════════════════════════
    public function movimientos(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', now()->format('Y-m-d'))->first();

            if (!$caja) return response()->json(['success'=>true,'data'=>[]]);

            $movimientos = CajaMovimientos::with('usuario')
                ->where('caja_id', $caja->id)->orderByDesc('created_at')->get();

            return response()->json([
                'success' => true,
                'data'    => $movimientos->map(fn($m) => [
                    'id'                   => $m->id,
                    'tipo'                 => $m->tipo,
                    'monto'                => (float) $m->monto,
                    'monto_formateado'     => '$'.number_format($m->monto,2),
                    'descripcion'          => $m->descripcion,
                    'referencia'           => $m->referencia,
                    'usuario'              => $m->usuario?->name,
                    'created_at'           => $m->created_at,
                    'created_at_formateado'=> $m->created_at->format('d/m/Y H:i'),
                ]),
            ]);

        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error al obtener movimientos','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CORTE (resumen del día)
    // ═════════════════════════════════════════════════════════════════════════
    public function corte(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $hoy = now()->format('Y-m-d');

            $caja = Caja::with(['usuarioApertura','usuarioCierre'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', $hoy)->first();

            if (!$caja) return response()->json(['success'=>false,'message'=>'No hay corte para hoy'], 404);

            $movimientos   = CajaMovimientos::where('caja_id', $caja->id)->get();
            $TotalIngresos = $movimientos->where('tipo','ingreso')->sum('monto');
            $TotalEgresos  = $movimientos->where('tipo','egreso')->sum('monto');

            return response()->json([
                'success' => true,
                'data'    => [
                    'caja' => [
                        'id'             => $caja->id,
                        'fecha_apertura' => $caja->fecha_apertura->format('d/m/Y H:i'),
                        'fecha_cierre'   => $caja->fecha_cierre?->format('d/m/Y H:i'),
                        'abierto_por'    => $caja->usuarioApertura?->name,
                        'cerrado_por'    => $caja->usuarioCierre?->name,
                    ],
                    'montos' => [
                        'monto_inicial' => (float) $caja->monto_inicial,
                        'monto_final'   => (float) $caja->monto_final,
                        'diferencia'    => (float) $caja->diferencia,
                    ],
                    'ventas' => [
                        'efectivo'      => (float) $caja->ventas_efectivo,
                        'tarjeta'       => (float) $caja->ventas_tarjeta,
                        'transferencia' => (float) $caja->ventas_transferencia,
                        'paypal'        => (float) $caja->ventas_paypal,
                        'mercadopago'   => (float) $caja->ventas_mercadopago,
                        'total'         => $caja->total_ventas,
                        'total_ordenes' => (int)   $caja->total_ordenes,
                    ],
                    'movimientos' => [
                        'ingresos'          => (float) $TotalIngresos,
                        'egresos'           => (float) $TotalEgresos,
                        'total_movimientos' => $movimientos->count(),
                    ],
                    'observaciones' => $caja->observaciones_cierre,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error al obtener corte','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // HISTORIAL
    // ═════════════════════════════════════════════════════════════════════════
    public function historial(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $perPage = $request->get('per_page', 15);

            $cortes = Caja::with(['usuarioApertura','usuarioCierre'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereNotNull('fecha_cierre')
                ->orderByDesc('fecha_cierre')
                ->paginate($perPage);

            return response()->json([
                'success'    => true,
                'data'       => $cortes->map(fn($c) => [
                    'id'             => $c->id,
                    'fecha'          => $c->fecha_apertura->format('d/m/Y'),
                    'apertura'       => $c->fecha_apertura->format('H:i'),
                    'cierre'         => $c->fecha_cierre->format('H:i'),
                    'monto_inicial'  => (float) $c->monto_inicial,
                    'monto_final'    => (float) $c->monto_final,
                    'ventas_totales' => $c->total_ventas,
                    'diferencia'     => (float) $c->diferencia,
                    'abierto_por'    => $c->usuarioApertura?->name,
                    'cerrado_por'    => $c->usuarioCierre?->name,
                ]),
                'pagination' => [
                    'current_page' => $cortes->currentPage(),
                    'per_page'     => $cortes->perPage(),
                    'total'        => $cortes->total(),
                    'last_page'    => $cortes->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error al obtener Historial','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SHOW (detalle por ID)
    // ═════════════════════════════════════════════════════════════════════════
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_CAJA')) {
                return response()->json(['success'=>false,'message'=>'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $caja = Caja::with(['usuarioApertura','usuarioCierre','movimientos.usuario'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)->firstOrFail();

            $movimientos   = $caja->movimientos->map(fn($m) => [
                'id'                   => $m->id,
                'tipo'                 => $m->tipo,
                'monto'                => (float) $m->monto,
                'monto_formateado'     => '$'.number_format($m->monto,2),
                'descripcion'          => $m->descripcion,
                'referencia'           => $m->referencia,
                'usuario'              => $m->usuario?->name,
                'created_at'           => $m->created_at,
                'created_at_formateado'=> $m->created_at->format('d/m/Y H:i'),
            ]);
            $TotalIngresos = $caja->movimientos->where('tipo','ingreso')->sum('monto');
            $TotalEgresos  = $caja->movimientos->where('tipo','egreso')->sum('monto');

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                       => $caja->id,
                    'restaurante_id'           => $caja->restaurante_id,
                    'fecha_apertura'           => $caja->fecha_apertura,
                    'fecha_apertura_formateada'=> $caja->fecha_apertura->format('d/m/Y H:i'),
                    'fecha_cierre'             => $caja->fecha_cierre,
                    'fecha_cierre_formateada'  => $caja->fecha_cierre?->format('d/m/Y H:i'),
                    'usuario_apertura'         => $caja->usuarioApertura
                        ? ['id'=>$caja->usuarioApertura->id,'name'=>$caja->usuarioApertura->name] : null,
                    'usuario_cierre'           => $caja->usuarioCierre
                        ? ['id'=>$caja->usuarioCierre->id,  'name'=>$caja->usuarioCierre->name]   : null,
                    'montos' => [
                        'monto_inicial' => (float) $caja->monto_inicial,
                        'monto_final'   => (float) $caja->monto_final,
                        'diferencia'    => (float) $caja->diferencia,
                    ],
                    'ventas' => [
                        'efectivo'      => (float) $caja->ventas_efectivo,
                        'tarjeta'       => (float) $caja->ventas_tarjeta,
                        'transferencia' => (float) $caja->ventas_transferencia,
                        'paypal'        => (float) $caja->ventas_paypal,
                        'mercadopago'   => (float) $caja->ventas_mercadopago,
                        'total'         => $caja->total_ventas,
                        'total_ordenes' => (int) $caja->total_ordenes,
                    ],
                    'movimientos' => [
                        'ingresos' => (float) $TotalIngresos,
                        'egresos'  => (float) $TotalEgresos,
                        'total'    => $movimientos->count(),
                        'lista'    => $movimientos,
                    ],
                    'observaciones' => $caja->observaciones_cierre,
                    'estado'        => $caja->estado,
                    'created_at'    => $caja->created_at,
                    'updated_at'    => $caja->updated_at,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success'=>false,'message'=>'Corte de caja no encontrado'], 404);
        } catch (\Exception $e) {
            return response()->json(['success'=>false,'message'=>'Error al obtener corte','error'=>$e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // CREAR PAGO CON PAYPAL (para una orden)
    // ═════════════════════════════════════════════════════════════════════════
    public function crearPagoPayPal(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para crear órdenes'
                ], 403);
            }

            $request->validate([
                'orden_id' => 'required|exists:ordenes,id',
                'total'    => 'required|numeric|min:0.01',
                'items'    => 'required|array|min:1',
            ]);

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('id', $request->orden_id)
                ->where('restaurante_id', $restauranteActivo->id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json([
                    'success' => false,
                    'message' => 'Esta Orden ya está cerrada'
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
                    'message' => 'No hay una caja abierta. Debes abrir la caja antes de procesar pagos.'
                ], 400);
            }

            // Crear orden en PayPal
            $paypalController = new PayPalController();
            
            // Crear request para PayPal
            $paypalRequest = new \Illuminate\Http\Request();
            $paypalRequest->merge([
                'total'    => $request->total,
                'items'    => $request->items,
                'order_id' => $orden->id,
            ]);

            $paypalResponse = $paypalController->createOrder($paypalRequest);
            $responseData = json_decode($paypalResponse->getContent(), true);

            if ($responseData['success']) {
                // Guardar el paypal_order_id en la orden
                $orden->paypal_order_id = $responseData['order_id'];
                $orden->save();

                return response()->json([
                    'success'      => true,
                    'approval_url' => $responseData['approval_url'],
                    'order_id'     => $responseData['order_id'],
                    'paypal_order_id' => $responseData['order_id']
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $responseData['message'] ?? 'Error al crear el pago con PayPal'
            ], 500);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Orden no encontrada'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error crear pago PayPal: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al procesar el pago: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Capturar pago de PayPal (callback después del pago exitoso)
     */
    public function capturarPayPal(Request $request)
    {
        try {
            $orderId = $request->query('token');
            
            if (!$orderId) {
                return redirect()->to(env('FRONTEND_URL') . '/pago-error');
            }

            // Buscar la orden por paypal_order_id
            $orden = Orden::where('paypal_order_id', $orderId)->firstOrFail();

            // Verificar que la caja esté abierta
            $restauranteActivo = $orden->restaurante;
            $caja = Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('fecha_apertura', now()->format('Y-m-d'))
                ->whereNull('fecha_cierre')
                ->first();

            if (!$caja) {
                return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=caja_cerrada');
            }

            // Capturar en PayPal
            $paypalController = new PayPalController();
            $captureResponse = $paypalController->captureOrder($request);
            
            // Actualizar la orden
            $orden->estado = 'CERRADA';
            $orden->metodo_pago = 'paypal';
            $orden->save();

            // Registrar movimiento en caja (opcional, ya que las ventas se calculan al cerrar caja)
            // Pero puedes registrar un ingreso por si acaso
            CajaMovimientos::create([
                'caja_id'     => $caja->id,
                'usuario_id'  => $orden->usuario_id,
                'tipo'        => 'ingreso',
                'monto'       => $orden->total,
                'descripcion' => 'Pago PayPal - Orden #' . $orden->id,
                'referencia'  => $orderId,
            ]);

            // Broadcast de actualización
            try {
                Broadcast::event(new \App\Events\CajaActualizada('venta', $restauranteActivo->id, [
                    'orden_id' => $orden->id,
                    'monto'    => (float) $orden->total,
                    'metodo'   => 'paypal'
                ]));
            } catch (\Exception $be) {
                \Log::warning('Broadcast pago PayPal: ' . $be->getMessage());
            }

            return redirect()->to(env('FRONTEND_URL') . '/pago-exitoso?orden=' . $orden->id);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            \Log::error('Orden no encontrada para PayPal: ' . $orderId);
            return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=orden_no_encontrada');
        } catch (\Exception $e) {
            \Log::error('Error capturar pago PayPal: ' . $e->getMessage());
            return redirect()->to(env('FRONTEND_URL') . '/pago-error?motivo=' . urlencode($e->getMessage()));
        }
    }
}