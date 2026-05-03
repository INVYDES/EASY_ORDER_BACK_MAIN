<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Paquete;
use App\Models\IngredienteMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdenController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para ver órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $perPage = min($request->get('per_page', 15), 100);
            $page    = $request->get('page', 1);

            $query = Orden::with([
                    'usuario:id,name,username,email',
                    'detalles.producto.categoria',
                ])
                ->where('restaurante_id', $restauranteActivo->id);

            if ($request->filled('estado')) {
                $query->whereIn('estado', explode(',', $request->estado));
            }
            if ($request->filled('user_id')) {
                $query->where('usuario_id', $request->user_id);
            }
            if ($request->filled('fecha_desde')) {
                $query->whereDate('created_at', '>=', $request->fecha_desde);
            }
            if ($request->filled('fecha_hasta')) {
                $query->whereDate('created_at', '<=', $request->fecha_hasta);
            }
            if ($request->filled('updated_at_desde')) {
                $query->where('updated_at', '>=', $request->updated_at_desde);
            }
            if ($request->filled('fecha')) {
                $query->whereDate('created_at', $request->fecha);
            }
            if ($request->filled('total_min')) {
                $query->where('total', '>=', $request->total_min);
            }
            if ($request->filled('total_max')) {
                $query->where('total', '<=', $request->total_max);
            }
            if ($request->filled('producto_id')) {
                $query->whereHas('detalles', fn($q) => $q->where('producto_id', $request->producto_id));
            }
            if ($request->filled('buscar')) {
                $b = $request->buscar;
                $query->where(fn($q) => $q
                    ->where('id', 'LIKE', "%{$b}%")
                    ->orWhereHas('detalles.producto', fn($sq) => $sq->where('nombre', 'LIKE', "%{$b}%"))
                );
            }

            $orderBy  = $request->get('order_by', 'created_at');
            $orderDir = $request->get('order_dir', 'desc');
            if (in_array($orderBy, ['id', 'total', 'estado', 'created_at', 'updated_at'])) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $ordenes     = $query->paginate($perPage, ['*'], 'page', $page);
            $ordenesData = $ordenes->map(fn($orden) => $this->transformarOrden($orden));

            $rid = $restauranteActivo->id;
            $hoy = now()->format('Y-m-d');

            $estadisticas = [
                'total_ordenes' => $ordenes->total(),
                'por_estado' => [
                    'abiertas'       => Orden::where('restaurante_id', $rid)->where('estado', 'ABIERTA')->count(),
                    'por_preparar'   => Orden::where('restaurante_id', $rid)->where('estado', 'POR_PREPARAR')->count(),
                    'en_preparacion' => Orden::where('restaurante_id', $rid)->where('estado', 'EN_PREPARACION')->count(),
                    'listas'         => Orden::where('restaurante_id', $rid)->where('estado', 'LISTA')->count(),
                    'entregadas'     => Orden::where('restaurante_id', $rid)->where('estado', 'ENTREGADA')->count(),
                    'cerradas'       => Orden::where('restaurante_id', $rid)->where('estado', 'CERRADA')->count(),
                ],
                'total_ventas_hoy' => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->where('estado', 'CERRADA')->sum('total'),
                'ordenes_hoy'      => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->count(),
            ];

            return response()->json([
                'success'    => true,
                'message'    => 'Órdenes obtenidas correctamente',
                'data'       => $ordenesData,
                'pagination' => [
                    'current_page'  => $ordenes->currentPage(),
                    'per_page'      => $ordenes->perPage(),
                    'total'         => $ordenes->total(),
                    'last_page'     => $ordenes->lastPage(),
                    'from'          => $ordenes->firstItem(),
                    'to'            => $ordenes->lastItem(),
                    'next_page_url' => $ordenes->nextPageUrl(),
                    'prev_page_url' => $ordenes->previousPageUrl(),
                ],
                'filters'    => [
                    'estado'      => $request->estado      ?? null,
                    'user_id'     => $request->user_id     ?? null,
                    'fecha_desde' => $request->fecha_desde ?? null,
                    'fecha_hasta' => $request->fecha_hasta ?? null,
                    'total_min'   => $request->total_min   ?? null,
                    'total_max'   => $request->total_max   ?? null,
                    'buscar'      => $request->buscar      ?? null,
                ],
                'estadisticas' => $estadisticas,
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener órdenes', 'error' => $e->getMessage()], 500);
        }
    }

    public function hoy(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $hoy   = now()->format('Y-m-d');
            $query = Orden::with(['usuario:id,name,username,email', 'detalles.producto.categoria'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy);

            if ($request->filled('estado')) {
                $query->whereIn('estado', explode(',', $request->estado));
            }

            $ordenes = $query->orderBy('created_at', 'desc')->get();

            return response()->json([
                'success' => true,
                'data'    => $ordenes->map(fn($orden) => $this->transformarOrden($orden)),
                'estadisticas' => [
                    'total'      => $ordenes->count(),
                    'por_estado' => [
                        'ABIERTA'        => $ordenes->where('estado', 'ABIERTA')->count(),
                        'POR_PREPARAR'   => $ordenes->where('estado', 'POR_PREPARAR')->count(),
                        'EN_PREPARACION' => $ordenes->where('estado', 'EN_PREPARACION')->count(),
                        'LISTA'          => $ordenes->where('estado', 'LISTA')->count(),
                        'ENTREGADA'      => $ordenes->where('estado', 'ENTREGADA')->count(),
                        'CERRADA'        => $ordenes->where('estado', 'CERRADA')->count(),
                    ],
                    'ventas_totales' => $ordenes->where('estado', 'CERRADA')->sum('total'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener órdenes de hoy', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with(['usuario:id,name,username,email', 'detalles.producto.categoria'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json(['success' => true, 'data' => $this->transformarOrden($orden)]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener orden', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // ACTIVIDAD: Agregar productos a orden abierta de la misma mesa
    // Si existe una orden no CERRADA para esa mesa, se anexan los productos.
    // Si no existe, se crea una nueva orden.
    // =========================================================================
    public function store(Request $request)
    {
        $request->validate([
            'cliente_id'              => 'nullable|exists:clientes,id',
            'productos'               => 'present|array',
            'paquetes'                => 'nullable|array',
            'productos.*.producto_id' => 'required_without:productos.*.paquete_id|nullable|exists:productos,id',
            'productos.*.paquete_id'  => 'required_without:productos.*.producto_id|nullable|exists:paquetes,id',
            'productos.*.cantidad'    => 'required|integer|min:1|max:100',
            'productos.*.notas'       => 'nullable|string|max:300',
            'notas'                   => 'nullable|string|max:500',
            'mesa'                    => 'nullable|integer|min:1',
            'metodo_pago'             => 'nullable|string|max:50',
            'propina'                 => 'nullable|numeric|min:0',
        ]);

        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para crear órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // -----------------------------------------------------------------
            // ACTIVIDAD 4: Buscar orden abierta para la misma mesa
            // Si hay una orden activa (no CERRADA) para esta mesa, se usará esa.
            // -----------------------------------------------------------------
            $ordenExistente = null;
            if ($request->filled('mesa')) {
                $ordenExistente = Orden::where('restaurante_id', $restauranteActivo->id)
                    ->where('mesa', $request->mesa)
                    ->whereNotIn('estado', ['CERRADA', 'CANCELADA', 'PAGADA'])
                    ->latest()
                    ->first();
            }

            // Verificar y preparar productos/ingredientes
            $erroresStock         = [];
            $productosVerificados = [];

            foreach ($request->productos as $item) {
                // --- PROCESAR PAQUETE ---
                if (!empty($item['paquete_id'])) {
                    $paquete = Paquete::with('productos.ingredientes')
                        ->where('restaurante_id', $restauranteActivo->id)
                        ->where('id', $item['paquete_id'])
                        ->first();

                    if (!$paquete) {
                        $erroresStock[] = "Paquete ID {$item['paquete_id']} no encontrado";
                        continue;
                    }

                    foreach ($paquete->productos as $pComp) {
                        $cantidadTotal = $pComp->pivot->cantidad * $item['cantidad'];

                        if ($pComp->ingredientes->isEmpty()) {
                            if ($pComp->stock < $cantidadTotal) {
                                $erroresStock[] = "Stock insuficiente para '{$pComp->nombre}' (en paquete {$paquete->nombre}). Disponible: {$pComp->stock}";
                            }
                        } else {
                            $maxDisponible = $pComp->ingredientes->map(function ($ing) use ($cantidadTotal) {
                                $necesario = $ing->pivot->cantidad * $cantidadTotal;
                                return $necesario > 0 ? floor($ing->stock_actual / $necesario) : PHP_INT_MAX;
                            })->min();

                            if ($maxDisponible < 1) {
                                $erroresStock[] = "Stock insuficiente para ingredientes de '{$pComp->nombre}' (en paquete {$paquete->nombre})";
                            }
                        }

                        if (empty($erroresStock)) {
                            $productosVerificados[] = [
                                'producto'       => $pComp,
                                'cantidad'       => $cantidadTotal,
                                'notas'          => $item['notas'] ?? null,
                                'precio'         => 0,
                                'paquete_id'     => $paquete->id,
                                'paquete_precio' => ($pComp->id === $paquete->productos->first()->id)
                                    ? $paquete->precio * $item['cantidad']
                                    : 0,
                            ];
                        }
                    }
                    continue;
                }

                // --- PROCESAR PRODUCTO INDIVIDUAL ---
                $producto = Producto::with(['ingredientes'])
                    ->where('restaurante_id', $restauranteActivo->id)
                    ->where('id', $item['producto_id'])
                    ->first();

                if (!$producto) {
                    $erroresStock[] = "Producto ID {$item['producto_id']} no encontrado";
                    continue;
                }

                if ($producto->ingredientes->isEmpty()) {
                    if ($producto->stock < $item['cantidad']) {
                        $erroresStock[] = "Stock insuficiente para '{$producto->nombre}'. Disponible: {$producto->stock}";
                    } else {
                        $productosVerificados[] = [
                            'producto' => $producto,
                            'cantidad' => $item['cantidad'],
                            'notas'    => $item['notas'] ?? null,
                            'precio'   => $producto->precio,
                        ];
                    }
                    continue;
                }

                $maxDisponible = $producto->ingredientes->map(function ($ing) use ($item) {
                    $necesario = $ing->pivot->cantidad * $item['cantidad'];
                    return $necesario > 0 ? floor($ing->stock_actual / $necesario) : PHP_INT_MAX;
                })->min();

                if ($maxDisponible < 1) {
                    $erroresStock[] = "Stock insuficiente para '{$producto->nombre}'. Cantidad solicitada: {$item['cantidad']}";
                } else {
                    $productosVerificados[] = [
                        'producto' => $producto,
                        'cantidad' => $item['cantidad'],
                        'notas'    => $item['notas'] ?? null,
                        'precio'   => $producto->precio,
                    ];
                }
            }

            if (!empty($erroresStock)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede crear la orden por problemas de stock',
                    'errors'  => $erroresStock,
                ], 422);
            }

            DB::beginTransaction();

            // -----------------------------------------------------------------
            // ACTIVIDAD 4: Si hay orden existente, usar esa; si no, crear nueva
            // -----------------------------------------------------------------
            if ($ordenExistente) {
                $orden   = $ordenExistente;
                $esNueva = false;
            } else {
                $orden = Orden::create([
                    'restaurante_id' => $restauranteActivo->id,
                    'cliente_id'     => $request->cliente_id,
                    'usuario_id'     => $user->id,
                    'mesa'           => $request->mesa,
                    'metodo_pago'    => $request->metodo_pago,
                    'total'          => 0,
                    'propina'        => $request->propina ?? 0,
                    'notas'          => $request->notas,
                    'estado'         => 'ABIERTA',
                ]);
                $esNueva = true;
            }

            $detalles    = [];
            $subtotalNuevo = 0;

            foreach ($productosVerificados as $item) {
                $productoModel = $item['producto'];
                $precio        = $item['precio'] ?? $productoModel->precio;
                $paqueteId     = $item['paquete_id'] ?? null;
                $paquetePrecio = $item['paquete_precio'] ?? 0;
                $subtotal      = ($precio * $item['cantidad']) + $paquetePrecio;

                $detalle = OrdenDetalle::create([
                    'orden_id'           => $orden->id,
                    'producto_id'        => $productoModel->id,
                    'paquete_id'         => $paqueteId,
                    'cantidad'           => $item['cantidad'],
                    'precio_unitario'    => $precio + ($item['cantidad'] > 0 ? ($paquetePrecio / $item['cantidad']) : 0),
                    'subtotal'           => $subtotal,
                    'notas'              => $item['notas'],
                    'estado_preparacion' => 'PENDIENTE',
                ]);

                $detalles[] = [
                    'id'                  => $detalle->id,
                    'producto_id'         => $productoModel->id,
                    'producto_nombre'     => $productoModel->nombre,
                    'categoria_id'        => $productoModel->categoria_id,
                    'categoria'           => $productoModel->categoria?->nombre,
                    'cantidad'            => $item['cantidad'],
                    'precio_unitario'     => (float) $detalle->precio_unitario,
                    'subtotal'            => (float) $subtotal,
                    'subtotal_formateado' => '$' . number_format($subtotal, 2),
                    'estado_preparacion'  => 'PENDIENTE',
                ];

                $subtotalNuevo += $subtotal;

                // -------------------------------------------------------------
                // ACTIVIDAD 1: Descontar stock al momento de pedir
                // Productos SIN ingredientes: descontar stock directo
                // Productos CON ingredientes: descontar stock de ingredientes
                // -------------------------------------------------------------
                if ($productoModel->ingredientes->isEmpty()) {
                    // Producto sin ingredientes — descontar stock directo
                    $productoModel->decrement('stock', $item['cantidad']);
                } else {
                    // Producto con ingredientes — descontar stock de cada ingrediente
                    foreach ($productoModel->ingredientes as $ingrediente) {
                        $cantidadPorPorcion = $ingrediente->pivot->cantidad ?? 0;
                        $cantidadADescontar = $cantidadPorPorcion * $item['cantidad'];

                        if ($cantidadADescontar <= 0) continue;

                        $anterior = $ingrediente->stock_actual;
                        $nueva    = $anterior - $cantidadADescontar;

                        if ($nueva < 0) {
                            // Aunque ya validamos, doble seguridad dentro de la transacción
                            throw new \Exception(
                                "Stock insuficiente de: {$ingrediente->nombre}. " .
                                "Disponible: {$anterior}, Necesario: {$cantidadADescontar}"
                            );
                        }

                        $ingrediente->decrement('stock_actual', $cantidadADescontar);

                        IngredienteMovimiento::create([
                            'ingrediente_id'      => $ingrediente->id,
                            'user_id'             => $user->id,
                            'tipo'                => 'salida',
                            'cantidad_anterior'   => $anterior,
                            'cantidad_movimiento' => $cantidadADescontar,
                            'cantidad_nueva'      => $nueva,
                            'motivo'              => "Venta de {$item['cantidad']}x {$productoModel->nombre} (Orden #{$orden->id})",
                            'orden_id'            => $orden->id,
                        ]);
                    }
                }
            }

            // Recalcular total de la orden (suma de todos los detalles)
            $totalActual   = $orden->detalles()->sum('subtotal');
            $propina       = $esNueva ? ($request->propina ?? 0) : ($orden->propina ?? 0);
            $totalConPropina = $totalActual + $propina;
            $orden->update(['total' => $totalConPropina]);

            DB::commit();

            $orden->load(['usuario:id,name,username', 'detalles.producto.categoria']);

            try {
                broadcast(new \App\Events\OrdenActualizada(
                    $orden,
                    $esNueva ? 'creada' : 'productos_agregados',
                    $restauranteActivo->id
                ));
            } catch (\Exception $be) {
                \Log::warning('Broadcast orden store: ' . $be->getMessage());
            }

            $mensaje = $esNueva
                ? 'Orden creada correctamente'
                : "Productos agregados a la orden #{$orden->id} de la mesa {$orden->mesa}";

            return response()->json([
                'success'   => true,
                'message'   => $mensaje,
                'es_nueva'  => $esNueva,
                'data'      => [
                    'id'               => $orden->id,
                    'folio'            => 'ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT),
                    'mesa'             => $orden->mesa,
                    'total'            => (float) $totalConPropina,
                    'total_formateado' => '$' . number_format($totalConPropina, 2),
                    'subtotal'         => (float) $totalActual,
                    'propina'          => (float) $propina,
                    'estado'           => $orden->estado,
                    'detalles_nuevos'  => $detalles,         // Solo los recién agregados
                    'detalles_totales' => $this->transformarOrden($orden)['detalles'], // Todos
                    'created_at'       => $orden->created_at,
                ],
            ], $esNueva ? 201 : 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear orden', 'error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'estado'      => 'required|in:ABIERTA,POR_PREPARAR,EN_PREPARACION,LISTA,ENTREGADA,CERRADA',
            'metodo_pago' => 'nullable|string|max:50',
            'propina'     => 'nullable|numeric|min:0',
        ]);

        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para editar órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            if (!$orden->puedeCambiarEstado($request->estado)) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede cambiar de {$orden->estado} a {$request->estado}",
                ], 400);
            }

            $estadoAnterior = $orden->estado;
            $campos = ['estado' => $request->estado];
            if ($request->filled('metodo_pago')) $campos['metodo_pago'] = $request->metodo_pago;
            if ($request->has('propina'))         $campos['propina']     = $request->propina ?? 0;

            $orden->update($campos);
            $orden->load(['usuario:id,name,username', 'detalles.producto.categoria']);

            try {
                broadcast(new \App\Events\OrdenActualizada(
                    $orden,
                    $request->estado === 'CERRADA' ? 'cerrada' : 'estado_cambiado',
                    $restauranteActivo->id
                ));

                if ($request->estado === 'CERRADA') {
                    broadcast(new \App\Events\CajaActualizada('venta', $restauranteActivo->id, [
                        'orden_id'    => $orden->id,
                        'total'       => (float) $orden->total,
                        'metodo_pago' => $orden->metodo_pago,
                        'propina'     => (float) ($orden->propina ?? 0),
                    ]));
                }
            } catch (\Exception $be) {
                \Log::warning('Broadcast orden update: ' . $be->getMessage());
            }

            if (method_exists($user, 'logAction')) {
                $user->logAction('EDITAR_ORDEN', 'ordenes', $orden->id,
                    "Orden #{$orden->id}: {$estadoAnterior} → {$request->estado}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden actualizada correctamente',
                'data'    => [
                    'id'           => $orden->id,
                    'estado'       => $orden->estado,
                    'estado_texto' => $this->getEstadoTexto($orden->estado),
                    'metodo_pago'  => $orden->metodo_pago,
                    'propina'      => (float) ($orden->propina ?? 0),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar orden', 'error' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // ACTIVIDAD 1: cerrar() ya NO descuenta stock porque store() lo hizo
    // Solo cambia el estado de la orden a CERRADA y registra en caja.
    // =========================================================================
    public function cerrar(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CERRAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para cerrar órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with(['detalles.producto.ingredientes', 'detalles.producto.categoria'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json(['success' => false, 'message' => 'La orden ya está cerrada'], 400);
            }

            // El stock ya fue descontado en store(). Solo cerramos la orden.
            DB::transaction(function () use ($orden) {
                $orden->update(['estado' => 'CERRADA']);
            });

            $orden->load(['usuario:id,name,username', 'detalles.producto.categoria']);

            try {
                broadcast(new \App\Events\OrdenActualizada($orden, 'cerrada', $restauranteActivo->id));
                broadcast(new \App\Events\CajaActualizada('venta', $restauranteActivo->id, [
                    'orden_id'    => $orden->id,
                    'total'       => (float) $orden->total,
                    'metodo_pago' => $orden->metodo_pago,
                    'propina'     => (float) ($orden->propina ?? 0),
                ]));
            } catch (\Exception $be) {
                \Log::warning('Broadcast orden cerrar: ' . $be->getMessage());
            }

            if (method_exists($user, 'logAction')) {
                $user->logAction('CERRAR_ORDEN', 'ordenes', $orden->id,
                    "Orden #{$orden->id} cerrada con total: \${$orden->total}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden cerrada correctamente',
                'data'    => [
                    'id'               => $orden->id,
                    'estado'           => 'CERRADA',
                    'total'            => (float) $orden->total,
                    'total_formateado' => '$' . number_format($orden->total, 2),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al cerrar orden', 'error' => $e->getMessage()], 500);
        }
    }

    public function dividirCuenta(Request $request, $id)
    {
        $request->validate([
            'metodo'                   => 'required|in:equitativo,manual',
            'comensales'               => 'required_if:metodo,equitativo|integer|min:2|max:20',
            'divisiones'               => 'required_if:metodo,manual|array|min:2',
            'divisiones.*.comensal'    => 'required|integer|min:1',
            'divisiones.*.detalles'    => 'required|array|min:1',
            'divisiones.*.detalles.*'  => 'integer|exists:orden_detalles,id',
        ]);

        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with(['detalles.producto'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json(['success' => false, 'message' => 'No se puede dividir una orden cerrada'], 400);
            }

            $totalOrden = (float) $orden->total;
            $cuentas    = [];

            if ($request->metodo === 'equitativo') {
                // -----------------------------------------------------------------
                // División equitativa: el total se divide entre N comensales.
                // Los detalles individuales no se mueven; el monto sí.
                // -----------------------------------------------------------------
                $numComensales = (int) $request->comensales;
                $montoPorPersona = round($totalOrden / $numComensales, 2);
                // Ajustar el último para cubrir residuo de centavos
                $montoUltimo = $totalOrden - ($montoPorPersona * ($numComensales - 1));

                for ($i = 1; $i <= $numComensales; $i++) {
                    $cuentas[] = [
                        'comensal'  => $i,
                        'monto'     => $i === $numComensales ? $montoUltimo : $montoPorPersona,
                        'monto_fmt' => '$' . number_format($i === $numComensales ? $montoUltimo : $montoPorPersona, 2),
                        'detalles'  => [], // Sin asignación específica de platillos
                    ];
                }

            } else {
                // -----------------------------------------------------------------
                // División manual: cada comensal tiene detalles específicos.
                // Se valida que todos los detalles pertenezcan a la orden
                // y que no haya detalles sin asignar.
                // -----------------------------------------------------------------
                $idsAsignados    = [];
                $idsEnOrden      = $orden->detalles->pluck('id')->toArray();
                $detallesMap     = $orden->detalles->keyBy('id');

                foreach ($request->divisiones as $div) {
                    $subtotalComensal = 0;
                    $detallesComensal = [];

                    foreach ($div['detalles'] as $detalleId) {
                        if (!in_array($detalleId, $idsEnOrden)) {
                            return response()->json([
                                'success' => false,
                                'message' => "El detalle #{$detalleId} no pertenece a esta orden",
                            ], 422);
                        }
                        if (in_array($detalleId, $idsAsignados)) {
                            return response()->json([
                                'success' => false,
                                'message' => "El detalle #{$detalleId} fue asignado a más de un comensal",
                            ], 422);
                        }

                        $idsAsignados[] = $detalleId;
                        $det = $detallesMap[$detalleId];
                        $subtotalComensal += (float) $det->subtotal;
                        $detallesComensal[] = [
                            'id'              => $det->id,
                            'producto_nombre' => $det->producto->nombre ?? 'Producto eliminado',
                            'cantidad'        => $det->cantidad,
                            'subtotal'        => (float) $det->subtotal,
                            'subtotal_fmt'    => '$' . number_format($det->subtotal, 2),
                        ];
                    }

                    $cuentas[] = [
                        'comensal'  => $div['comensal'],
                        'monto'     => $subtotalComensal,
                        'monto_fmt' => '$' . number_format($subtotalComensal, 2),
                        'detalles'  => $detallesComensal,
                    ];
                }

                // Verificar que todos los detalles de la orden estén asignados
                $sinAsignar = array_diff($idsEnOrden, $idsAsignados);
                if (!empty($sinAsignar)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Hay productos sin asignar a ningún comensal',
                        'detalles_sin_asignar' => array_values($sinAsignar),
                    ], 422);
                }
            }

            return response()->json([
                'success'     => true,
                'message'     => 'División de cuenta calculada',
                'orden_id'    => $orden->id,
                'folio'       => 'ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT),
                'total'       => $totalOrden,
                'total_fmt'   => '$' . number_format($totalOrden, 2),
                'metodo'      => $request->metodo,
                'cuentas'     => $cuentas,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al dividir cuenta', 'error' => $e->getMessage()], 500);
        }
    }

    public function resumen(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $rid = app('restaurante_activo')->id;
            $hoy = now()->format('Y-m-d');

            return response()->json([
                'success' => true,
                'data'    => [
                    'hoy' => [
                        'total'  => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->count(),
                        'ventas' => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->where('estado', 'CERRADA')->sum('total'),
                    ],
                    'por_estado' => [
                        'abiertas'       => Orden::where('restaurante_id', $rid)->where('estado', 'ABIERTA')->count(),
                        'por_preparar'   => Orden::where('restaurante_id', $rid)->where('estado', 'POR_PREPARAR')->count(),
                        'en_preparacion' => Orden::where('restaurante_id', $rid)->where('estado', 'EN_PREPARACION')->count(),
                        'listas'         => Orden::where('restaurante_id', $rid)->where('estado', 'LISTA')->count(),
                        'entregadas'     => Orden::where('restaurante_id', $rid)->where('estado', 'ENTREGADA')->count(),
                        'cerradas'       => Orden::where('restaurante_id', $rid)->where('estado', 'CERRADA')->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener resumen', 'error' => $e->getMessage()], 500);
        }
    }

    private function transformarOrden(Orden $orden): array
    {
        $u = $orden->usuario ?? $orden->user ?? null;

        return [
            'id'                    => $orden->id,
            'restaurante_id'        => $orden->restaurante_id,
            'folio'                 => 'ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT),
            'user'                  => $u ? [
                'id'       => $u->id,
                'name'     => $u->name,
                'username' => $u->username,
                'email'    => $u->email ?? null,
            ] : null,
            'estado'                => $orden->estado,
            'estado_texto'          => $this->getEstadoTexto($orden->estado),
            'estado_color'          => $this->getEstadoColor($orden->estado),
            'total'                 => (float) $orden->total,
            'total_formateado'      => '$' . number_format($orden->total, 2),
            'mesa'                  => $orden->mesa,
            'metodo_pago'           => $orden->metodo_pago,
            'propina'               => (float) ($orden->propina ?? 0),
            'notas'                 => $orden->notas,
            'cantidad_productos'    => $orden->detalles->sum('cantidad'),
            'productos_unicos'      => $orden->detalles->count(),
            'detalles'              => $orden->detalles->map(fn($d) => [
                'id'                  => $d->id,
                'producto_id'         => $d->producto_id,
                'producto_nombre'     => $d->producto->nombre ?? 'Producto eliminado',
                'producto'            => [
                    'id'           => $d->producto_id,
                    'nombre'       => $d->producto->nombre ?? 'Producto eliminado',
                    'categoria_id' => $d->producto->categoria_id ?? null,
                    'categoria'    => $d->producto->categoria ? [
                        'id'     => $d->producto->categoria->id,
                        'nombre' => $d->producto->categoria->nombre,
                    ] : null,
                ],
                'categoria_id'        => $d->producto->categoria_id ?? null,
                'categoria'           => $d->producto->categoria?->nombre ?? null,
                'cantidad'            => $d->cantidad,
                'precio_unitario'     => (float) $d->precio_unitario,
                'precio_formateado'   => '$' . number_format($d->precio_unitario, 2),
                'subtotal'            => (float) $d->subtotal,
                'subtotal_formateado' => '$' . number_format($d->subtotal, 2),
                'notas'               => $d->notas ?? null,
                'estado_preparacion'  => $d->estado_preparacion ?? 'PENDIENTE',
            ]),
            'created_at'            => $orden->created_at,
            'created_at_formateado' => $orden->created_at->format('d/m/Y H:i'),
            'created_at_humano'     => $orden->created_at->diffForHumans(),
            'updated_at'            => $orden->updated_at,
            'updated_at_formateado' => $orden->updated_at->format('d/m/Y H:i'),
        ];
    }

    private function getEstadoTexto(string $estado): string
    {
        return ['ABIERTA' => 'Abierta', 'POR_PREPARAR' => 'Por preparar', 'EN_PREPARACION' => 'En preparación', 'LISTA' => 'Lista', 'ENTREGADA' => 'Entregada', 'CERRADA' => 'Cerrada'][$estado] ?? $estado;
    }

    private function getEstadoColor(string $estado): string
    {
        return ['ABIERTA' => 'yellow', 'POR_PREPARAR' => 'orange', 'EN_PREPARACION' => 'blue', 'LISTA' => 'green', 'ENTREGADA' => 'purple', 'CERRADA' => 'gray'][$estado] ?? 'gray';
    }

    private function puedeCambiarEstado(string $actual, string $nuevo): bool
    {
        $transiciones = [
            'ABIERTA'        => ['POR_PREPARAR', 'CERRADA'],
            'POR_PREPARAR'   => ['EN_PREPARACION', 'CERRADA'],
            'EN_PREPARACION' => ['LISTA', 'CERRADA'],
            'LISTA'          => ['ENTREGADA', 'CERRADA'],
            'ENTREGADA'      => ['CERRADA'],
            'CERRADA'        => [],
        ];
        return in_array($nuevo, $transiciones[$actual] ?? []);
    }

    public function updateStationStatus(Request $request, $id)
    {
        $request->validate([
            'detalles'           => 'required|array|min:1',
            'detalles.*'         => 'exists:orden_detalles,id',
            'estado_preparacion' => 'required|in:PENDIENTE,EN_PREPARACION,LISTO,ENTREGADO',
        ]);

        try {
            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::with('detalles')
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            OrdenDetalle::whereIn('id', $request->detalles)
                ->where('orden_id', $orden->id)
                ->update(['estado_preparacion' => $request->estado_preparacion]);

            $orden->verificarYActualizarEstadoGlobal();

            return response()->json([
                'success' => true,
                'message' => 'Estado de preparación actualizado',
                'data'    => [
                    'orden_id'          => $orden->id,
                    'nuevo_estado_orden' => $orden->estado,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar', 'error' => $e->getMessage()], 500);
        }
    }
}