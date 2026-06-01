<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Paquete;
use App\Http\Resources\OrdenResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class OrdenController extends Controller
{
    /**
     * Listar órdenes con paginación y filtros
     */
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
                    'detalles' => function($q) { $q->withTrashed()->with('producto.categoria'); },
                    'cliente:id,nombre,telefono'
                ])
                ->where('restaurante_id', $restauranteActivo->id);

            // Filtros
            if ($request->filled('estado')) {
                $query->whereIn('estado', explode(',', $request->estado));
            }
            if ($request->filled('tipo_orden')) {
                $query->whereIn('tipo_orden', explode(',', $request->tipo_orden));
            }
            if ($request->filled('user_id')) {
                $query->where('usuario_id', $request->user_id);
            }
            if ($request->filled('mesa')) {
                $query->where('mesa', $request->mesa);
            }
            if ($request->filled('fecha_desde')) {
                try {
                    $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $request->fecha_desde . ' 00:00:00', 'America/Mexico_City');
                    $query->where('created_at', '>=', $start);
                } catch (\Exception $e) {
                    $query->whereDate('created_at', '>=', $request->fecha_desde);
                }
            }
            if ($request->filled('fecha_hasta')) {
                try {
                    $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $request->fecha_hasta . ' 23:59:59', 'America/Mexico_City');
                    $query->where('created_at', '<=', $end);
                } catch (\Exception $e) {
                    $query->whereDate('created_at', '<=', $request->fecha_hasta);
                }
            }
            if ($request->filled('fecha')) {
                try {
                    $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $request->fecha . ' 00:00:00', 'America/Mexico_City');
                    $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $request->fecha . ' 23:59:59', 'America/Mexico_City');
                    $query->whereBetween('created_at', [$start, $end]);
                } catch (\Exception $e) {
                    $query->whereDate('created_at', $request->fecha);
                }
            }
            if ($request->filled('updated_at_desde')) {
                try {
                    $date = \Carbon\Carbon::parse($request->updated_at_desde);
                    // Si el string es interpretado en el futuro (típico desfase UTC en clientes de México), restamos 6 horas
                    if ($date->isFuture()) {
                        $date->subHours(6);
                    }
                    $query->where('updated_at', '>=', $date);
                } catch (\Exception $e) {
                    $query->where('updated_at', '>=', $request->updated_at_desde);
                }
            }
            if ($request->filled('updated_at_hasta')) {
                try {
                    $date = \Carbon\Carbon::parse($request->updated_at_hasta);
                    if ($date->isFuture()) {
                        $date->subHours(6);
                    }
                    $query->where('updated_at', '<=', $date);
                } catch (\Exception $e) {
                    $query->where('updated_at', '<=', $request->updated_at_hasta);
                }
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
            if (in_array($orderBy, ['id', 'total', 'estado', 'tipo_orden', 'created_at', 'updated_at'])) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $ordenes = $query->paginate($perPage, ['*'], 'page', $page);

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
                'por_tipo' => [
                    'local'    => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'local')->count(),
                    'pickup'   => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'pickup')->count(),
                    'delivery' => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'delivery')->count(),
                ],
                'total_ventas_hoy' => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->whereIn('estado', ['CERRADA', 'ENTREGADA'])->sum('total'),
                'ordenes_hoy'      => Orden::where('restaurante_id', $rid)->whereDate('created_at', $hoy)->count(),
            ];

            return OrdenResource::collection($ordenes)->additional([
                'success'    => true,
                'message'    => 'Órdenes obtenidas correctamente',
                'statistics' => $estadisticas,
                'filters'    => [
                    'estado'      => $request->estado      ?? null,
                    'tipo_orden'  => $request->tipo_orden  ?? null,
                    'user_id'     => $request->user_id     ?? null,
                    'fecha_desde' => $request->fecha_desde ?? null,
                    'fecha_hasta' => $request->fecha_hasta ?? null,
                    'total_min'   => $request->total_min   ?? null,
                    'total_max'   => $request->total_max   ?? null,
                    'buscar'      => $request->buscar      ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener órdenes', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Órdenes del día de hoy
     */
    public function hoy(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $hoy   = now()->format('Y-m-d');
            $query = Orden::with([
                    'usuario:id,name,username,email',
                    'detalles' => function($q) { $q->withTrashed()->with('producto.categoria'); },
                    'cliente'
                ])
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy);

            if ($request->filled('estado')) {
                $query->whereIn('estado', explode(',', $request->estado));
            }
            if ($request->filled('tipo_orden')) {
                $query->where('tipo_orden', $request->tipo_orden);
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
                    'por_tipo' => [
                        'local'    => $ordenes->where('tipo_orden', 'local')->count(),
                        'pickup'   => $ordenes->where('tipo_orden', 'pickup')->count(),
                        'delivery' => $ordenes->where('tipo_orden', 'delivery')->count(),
                    ],
                    'ventas_totales' => $ordenes->whereIn('estado', ['CERRADA', 'ENTREGADA'])->sum('total'),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener órdenes de hoy', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Mostrar una orden específica
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with([
                    'usuario:id,name,username,email',
                    'detalles' => function($q) { $q->withTrashed()->with('producto.categoria'); },
                    'cliente'
                ])
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

    /**
     * Crear nueva orden o agregar productos a orden existente de la misma mesa
     * Soporta Local, Pickup y Delivery
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'cliente_id'              => 'nullable|exists:clientes,id',
            'productos'               => 'present|array',
            'paquetes'                => 'nullable|array',
            'productos.*.producto_id' => 'required_without:productos.*.paquete_id|nullable|exists:productos,id',
            'productos.*.paquete_id'  => 'required_without:productos.*.producto_id|nullable|exists:paquetes,id',
            'productos.*.cantidad'    => 'required|numeric|min:0.1|max:100',
            'productos.*.notas'       => 'nullable|string|max:300',
            'productos.*.comensal'    => 'nullable|string|max:100',
            'productos.*.comensal_id' => 'nullable|integer',
            'productos.*.nom_comensal'=> 'nullable|string|max:100',
            'notas'                   => 'nullable|string|max:500',
            'mesa'                    => 'required_if:tipo_orden,local|nullable|integer|min:1',
            'metodo_pago'             => 'nullable|string|max:50',
            'propina'                 => 'nullable|numeric|min:0',
            'tipo_orden'              => 'nullable|in:local,pickup,delivery',
            'direccion_entrega'       => 'required_if:tipo_orden,delivery|nullable|string|max:500',
            'telefono_contacto'       => 'required_if:tipo_orden,delivery|nullable|string|max:20',
            'costo_envio'             => 'nullable|numeric|min:0',
            'tiempo_estimado_entrega' => 'nullable|integer|min:1|max:180',
            'nombre_cliente'          => 'required_if:tipo_orden,delivery|nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para crear órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $tipoOrden = $request->tipo_orden ?? 'local';

            // Buscar orden existente SOLO para tipo local (misma mesa)
            $ordenExistente = null;
            if ($tipoOrden === 'local' && $request->filled('mesa')) {
                $ordenExistente = Orden::where('restaurante_id', $restauranteActivo->id)
                    ->where('mesa', $request->mesa)
                    ->where('tipo_orden', 'local')
                    ->whereNotIn('estado', ['CERRADA', 'CANCELADA', 'PAGADA'])
                    ->latest()
                    ->first();
            }

            // Verificar stock
            $erroresStock = [];
            $productosVerificados = [];

            foreach ($request->productos as $item) {
                // Procesar paquete
                if (!empty($item['paquete_id'])) {
                    // FIX: agregado 'productos.categoria' para cargar la categoría
                    $paquete = Paquete::with(['productos.ingredientes', 'productos.categoria'])
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
                                $erroresStock[] = "Stock insuficiente para '{$pComp->nombre}'. Disponible: {$pComp->stock}";
                            }
                        } else {
                            $maxDisponible = $pComp->ingredientes->map(function ($ing) use ($cantidadTotal) {
                                $necesario = $ing->pivot->cantidad * $cantidadTotal;
                                return $necesario > 0 ? floor($ing->stock_actual / $necesario) : PHP_INT_MAX;
                            })->min();

                            if ($maxDisponible < 1) {
                                $erroresStock[] = "Stock insuficiente para ingredientes de '{$pComp->nombre}'";
                            }
                        }

                        if (empty($erroresStock)) {
                            $productosVerificados[] = [
                                'producto'       => $pComp,
                                'cantidad'       => $cantidadTotal,
                                'notas'          => $item['notas'] ?? null,
                                'nom_comensal'   => $item['nom_comensal'] ?? null,
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

                // Procesar producto individual
                // FIX: agregado 'categoria' al with para cargar la relación
                $producto = Producto::with(['ingredientes', 'categoria'])
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
                            'producto'     => $producto,
                            'cantidad'     => $item['cantidad'],
                            'notas'        => $item['notas'] ?? null,
                            'nom_comensal' => $item['comensal'] ?? $item['nom_comensal'] ?? null,
                            'comensal_id'  => $item['comensal_id'] ?? null,
                            'precio'       => $producto->precio,
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
                        'producto'     => $producto,
                        'cantidad'     => $item['cantidad'],
                        'notas'        => $item['notas'] ?? null,
                        'nom_comensal' => $item['comensal'] ?? $item['nom_comensal'] ?? null,
                        'comensal_id'  => $item['comensal_id'] ?? null,
                        'precio'       => $producto->precio,
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

            // Crear o usar orden existente
            if ($ordenExistente) {
                $orden   = $ordenExistente;
                $esNueva = false;
            } else {
                // Para delivery, crear cliente temporal si no existe
                $clienteId = $request->cliente_id;
                if ($tipoOrden === 'delivery' && !$clienteId && $request->filled('nombre_cliente')) {
                    $cliente = \App\Models\Cliente::create([
                        'restaurante_id' => $restauranteActivo->id,
                        'nombre'         => $request->nombre_cliente,
                        'telefono'       => $request->telefono_contacto,
                    ]);
                    $clienteId = $cliente->id;
                }

                $orden = Orden::create([
                    'restaurante_id'          => $restauranteActivo->id,
                    'cliente_id'              => $clienteId,
                    'usuario_id'              => $user->id,
                    'mesa'                    => $tipoOrden === 'local' ? $request->mesa : null,
                    'tipo_orden'              => $tipoOrden,
                    'direccion_entrega'       => $request->direccion_entrega,
                    'telefono_contacto'       => $request->telefono_contacto,
                    'costo_envio'             => $request->costo_envio ?? 0,
                    'tiempo_estimado_entrega' => $request->tiempo_estimado_entrega,
                    'metodo_pago'             => $request->metodo_pago,
                    'total'                   => 0,
                    'propina'                 => $request->propina ?? 0,
                    'notas'                   => $request->notas,
                    'estado'                  => 'ABIERTA',
                ]);
                $esNueva = true;
            }

            $detalles      = [];
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
                    'nom_comensal'       => $item['nom_comensal'] ?? ($tipoOrden !== 'local' ? 'Para llevar' : null),
                    'estado_preparacion' => 'ABIERTA',
                ]);

                \App\Helpers\StockHelper::descontarStock($detalle, $item['cantidad'], $user->id);

                // FIX: categoria ahora disponible porque se cargó con with(['ingredientes','categoria'])
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
                    'notas'               => $item['notas'],
                    'nom_comensal'        => $item['nom_comensal'],
                    'estado_preparacion'  => 'ABIERTA',
                ];

                $subtotalNuevo += $subtotal;
            }

            // Recalcular total de la orden
            $totalActual           = $orden->detalles()->sum('subtotal');
            $propina               = $esNueva ? ($request->propina ?? 0) : ($orden->propina ?? 0);
            $costoEnvio            = $tipoOrden === 'delivery' ? ($request->costo_envio ?? $orden->costo_envio ?? 0) : 0;
            $totalConPropinaYEnvio = $totalActual + $propina + $costoEnvio;

            $updateData = [
                'total'       => $totalConPropinaYEnvio,
                'costo_envio' => $costoEnvio,
            ];

            // Si es una orden existente que ya no está ABIERTA, forzar regreso a ABIERTA
            // para que los nuevos productos aparezcan en la pestaña "Nuevas" del mesero.
            if (!$esNueva && $orden->estado !== 'ABIERTA') {
                $updateData['estado'] = 'ABIERTA';
            }

            $orden->update($updateData);

            DB::commit();

            // FIX: carga completa incluyendo categoria para transformarOrden
            $orden->load([
                'usuario:id,name,username',
                'detalles.producto.categoria',
                'cliente',
            ]);

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
                ? "Orden {$tipoOrden} creada correctamente"
                : "Productos agregados a la orden #{$orden->id}";

            return response()->json([
                'success'  => true,
                'message'  => $mensaje,
                'es_nueva' => $esNueva,
                'data'     => [
                    'id'                     => $orden->id,
                    'folio'                  => $orden->folio,
                    'tipo_orden'             => $orden->tipo_orden,
                    'tipo_orden_texto'       => $orden->tipo_orden_texto,
                    'mesa'                   => $orden->mesa,
                    'direccion_entrega'      => $orden->direccion_entrega,
                    'telefono_contacto'      => $orden->telefono_contacto,
                    'costo_envio'            => (float) $costoEnvio,
                    'costo_envio_formateado' => '$' . number_format($costoEnvio, 2),
                    'total'                  => (float) $totalConPropinaYEnvio,
                    'total_formateado'       => '$' . number_format($totalConPropinaYEnvio, 2),
                    'subtotal'               => (float) $totalActual,
                    'propina'                => (float) $propina,
                    'estado'                 => $orden->estado,
                    'detalles_nuevos'        => $detalles,
                    'detalles_totales'       => $this->transformarOrden($orden)['detalles'],
                    'created_at'             => $orden->created_at,
                ],
            ], $esNueva ? 201 : 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear orden', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar estado de una orden
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'estado'      => 'required|in:ABIERTA,POR_PREPARAR,EN_PREPARACION,LISTA,ENTREGADA,CERRADA,CANCELADA',
            'metodo_pago' => 'nullable|string|max:50',
            'propina'     => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $validator->errors()], 422);
        }

        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para editar órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $estadoAnterior = $orden->estado;

            if ($request->estado === 'POR_PREPARAR') {
                // Actualizar todos los detalles ABIERTA de esta orden a PENDIENTE (para mandarlos a estación)
                $orden->detalles()->where('estado_preparacion', 'ABIERTA')->update(['estado_preparacion' => 'PENDIENTE']);
                
                // Recalcular estado global de la orden
                $orden->verificarYActualizarEstadoGlobal();
            } else {
                if (!$orden->puedeCambiarEstado($request->estado)) {
                    return response()->json([
                        'success' => false,
                        'message' => "No se puede cambiar de {$orden->estado} a {$request->estado}",
                    ], 400);
                }

                $campos = ['estado' => $request->estado];
                if ($request->estado === 'LISTA') {
                    $campos['lista_at'] = now();
                }
                if ($request->filled('metodo_pago')) $campos['metodo_pago'] = $request->metodo_pago;
                if ($request->has('propina'))         $campos['propina']     = $request->propina ?? 0;

                $orden->update($campos);
            }

            // Si se cancela, restaurar stock
            if ($request->estado === 'CANCELADA') {
                $this->restaurarStockOrden($orden);
            }

            $orden->load([
                'usuario:id,name,username',
                'detalles.producto.categoria',
                'cliente',
            ]);

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
                        'tipo_orden'  => $orden->tipo_orden,
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
                    'estado_texto' => $orden->estado_texto,
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

    /**
     * Cerrar orden (con soporte para división de pagos)
     */
    public function cerrar(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('CERRAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para cerrar órdenes'], 403);
            }

            $request->validate([
                'metodo_pago'        => 'nullable|string|max:50',
                'propina'            => 'nullable|numeric|min:0',
                'referencia'         => 'nullable|string|max:100',
                'pagos'              => 'nullable|array',
                'pagos.*.monto'      => 'required_with:pagos|numeric|min:0',
                'pagos.*.metodo'     => 'required_with:pagos|string|max:50',
                'pagos.*.propina'    => 'nullable|numeric|min:0',
                'pagos.*.comensal'   => 'nullable|string|max:100',
                'pagos.*.referencia' => 'nullable|string|max:100',
                'pagos.*.detalles'   => 'nullable|array',
            ]);

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with(['detalles.producto.ingredientes', 'detalles.producto.categoria'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json(['success' => false, 'message' => 'La orden ya está cerrada'], 400);
            }

            $caja = \App\Models\Caja::where('restaurante_id', $restauranteActivo->id)
                ->whereNull('fecha_cierre')
                ->latest()
                ->first();

            $ordenesCreadas = [];

            DB::transaction(function () use ($orden, $request, $caja, $user, &$ordenesCreadas) {
                if ($request->filled('pagos')) {
                    foreach ($request->pagos as $index => $p) {
                        $pTotal     = (float) $p['monto'] + (float) ($p['propina'] ?? 0);
                        $metodoPago = $p['metodo'] ?? $request->metodo_pago ?? 'efectivo';

                        if ($index === 0) {
                            $orden->update([
                                'estado'      => 'CERRADA',
                                'metodo_pago' => $metodoPago,
                                'propina'     => (float) ($p['propina'] ?? 0),
                                'total'       => $pTotal,
                            ]);
                            $orderIdForLog    = $orden->id;
                            $ordenesCreadas[] = $orden->id;
                        } else {
                            $nuevaOrden = Orden::create([
                                'restaurante_id'    => $orden->restaurante_id,
                                'cliente_id'        => $orden->cliente_id,
                                'usuario_id'        => $orden->usuario_id,
                                'mesa'              => $orden->mesa,
                                'tipo_orden'        => $orden->tipo_orden,
                                'direccion_entrega' => $orden->direccion_entrega,
                                'telefono_contacto' => $orden->telefono_contacto,
                                'estado'            => 'CERRADA',
                                'metodo_pago'       => $metodoPago,
                                'propina'           => (float) ($p['propina'] ?? 0),
                                'total'             => $pTotal,
                                'created_at'        => $orden->created_at,
                            ]);

                            if (!empty($p['detalles'])) {
                                \App\Models\OrdenDetalle::whereIn('id', $p['detalles'])
                                    ->update(['orden_id' => $nuevaOrden->id]);
                            }
                            $orderIdForLog    = $nuevaOrden->id;
                            $ordenesCreadas[] = $nuevaOrden->id;
                        }

                        if ($caja) {
                            \App\Models\CajaMovimientos::create([
                                'caja_id'     => $caja->id,
                                'usuario_id'  => $user->id,
                                'tipo'        => 'ingreso',
                                'monto'       => $pTotal,
                                'descripcion' => "Venta - Orden #{$orderIdForLog} ({$metodoPago}) - " . ($p['comensal'] ?? 'Ticket'),
                                'referencia'  => $p['referencia'] ?? '',
                            ]);
                        }

                        try {
                            broadcast(new \App\Events\CajaActualizada('venta', $orden->restaurante_id, [
                                'orden_id'    => $orderIdForLog,
                                'total'       => $pTotal,
                                'metodo_pago' => $metodoPago,
                                'propina'     => (float) ($p['propina'] ?? 0),
                                'tipo_orden'  => $orden->tipo_orden,
                                'comensal'    => $p['comensal'] ?? '',
                            ]));
                        } catch (\Exception $e) {
                            \Log::warning('Broadcast CajaActualizada failed: ' . $e->getMessage());
                        }
                    }
                } else {
                    $orden->update([
                        'estado'      => 'CERRADA',
                        'metodo_pago' => $request->metodo_pago,
                        'propina'     => $request->propina ?? 0,
                        'referencia'  => $request->referencia,
                        'total'       => $orden->detalles()->sum('subtotal') + ($request->propina ?? 0),
                    ]);

                    if ($caja) {
                        $mPago = $request->metodo_pago ?? 'efectivo';
                        \App\Models\CajaMovimientos::create([
                            'caja_id'     => $caja->id,
                            'usuario_id'  => $user->id,
                            'tipo'        => 'ingreso',
                            'monto'       => $orden->total,
                            'descripcion' => "Venta - Orden #{$orden->id} ({$mPago})",
                            'referencia'  => $request->referencia ?? '',
                        ]);
                    }

                    try {
                        broadcast(new \App\Events\CajaActualizada('venta', $orden->restaurante_id, [
                            'orden_id'    => $orden->id,
                            'total'       => (float) $orden->total,
                            'metodo_pago' => $orden->metodo_pago,
                            'propina'     => (float) ($orden->propina ?? 0),
                            'tipo_orden'  => $orden->tipo_orden,
                        ]));
                    } catch (\Exception $e) {
                        \Log::warning('Broadcast CajaActualizada fallback failed: ' . $e->getMessage());
                    }
                }
            });

            $orden->load([
                'usuario:id,name,username',
                'detalles.producto.categoria',
            ]);

            try {
                broadcast(new \App\Events\OrdenActualizada($orden, 'cerrada', $restauranteActivo->id));
            } catch (\Exception $be) {
                \Log::warning('Broadcast orden cerrar: ' . $be->getMessage());
            }

            if (method_exists($user, 'logAction')) {
                $user->logAction('CERRAR_ORDEN', 'ordenes', $orden->id,
                    "Orden #{$orden->id} ({$orden->tipo_orden}) cerrada con total: \${$orden->total}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Orden cerrada correctamente',
                'data'    => [
                    'id'               => $orden->id,
                    'folio'            => $orden->folio,
                    'tipo_orden'       => $orden->tipo_orden,
                    'estado'           => 'CERRADA',
                    'total'            => (float) $orden->total,
                    'total_formateado' => '$' . number_format($orden->total, 2),
                    'ordenes_ids'      => $ordenesCreadas,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al cerrar orden', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Dividir cuenta entre comensales
     */
    public function dividirCuenta(Request $request, $id)
    {
        $request->validate([
            'metodo'                   => 'required|in:equitativo,manual',
            'comensales'               => 'required_if:metodo,equitativo|integer|min:2|max:20',
            'divisiones'               => 'required_if:metodo,manual|array|min:2',
            'divisiones.*.comensal'    => 'required|string|max:100',
            'divisiones.*.comensal_id' => 'nullable|integer',
            'divisiones.*.detalles'    => 'required|array|min:1',
            'divisiones.*.detalles.*'  => 'integer|exists:orden_detalles,id',
        ]);

        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');
            $orden = Orden::with(['detalles.producto.categoria'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            if ($orden->estado === 'CERRADA') {
                return response()->json(['success' => false, 'message' => 'No se puede dividir una orden cerrada'], 400);
            }

            $totalOrden = (float) $orden->total;
            $cuentas    = [];

            if ($request->metodo === 'equitativo') {
                $numComensales   = (int) $request->comensales;
                $montoPorPersona = round($totalOrden / $numComensales, 2);
                $montoUltimo     = $totalOrden - ($montoPorPersona * ($numComensales - 1));

                for ($i = 1; $i <= $numComensales; $i++) {
                    $cuentas[] = [
                        'comensal'  => $i,
                        'monto'     => $i === $numComensales ? $montoUltimo : $montoPorPersona,
                        'monto_fmt' => '$' . number_format($i === $numComensales ? $montoUltimo : $montoPorPersona, 2),
                        'detalles'  => [],
                    ];
                }
            } else {
                $idsAsignados = [];
                $idsEnOrden   = $orden->detalles->pluck('id')->toArray();
                $detallesMap  = $orden->detalles->keyBy('id');

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

                        $idsAsignados[]    = $detalleId;
                        $det               = $detallesMap[$detalleId];
                        $subtotalComensal += (float) $det->subtotal;
                        $detallesComensal[] = [
                            'id'              => $det->id,
                            'producto_nombre' => $det->producto->nombre ?? 'Producto eliminado',
                            'categoria_id'    => $det->producto->categoria_id ?? null,
                            'categoria'       => $det->producto->categoria?->nombre ?? null,
                            'cantidad'        => $det->cantidad,
                            'subtotal'        => (float) $det->subtotal,
                            'subtotal_fmt'    => '$' . number_format($det->subtotal, 2),
                        ];
                    }

                    $cuentas[] = [
                        'comensal'     => $div['comensal'],
                        'comensal_id'  => $div['comensal_id'] ?? null,
                        'monto'        => $subtotalComensal,
                        'monto_fmt'    => '$' . number_format($subtotalComensal, 2),
                        'detalles'     => $detallesComensal,
                    ];
                }

                $sinAsignar = array_diff($idsEnOrden, $idsAsignados);
                if (!empty($sinAsignar)) {
                    return response()->json([
                        'success'              => false,
                        'message'              => 'Hay productos sin asignar a ningún comensal',
                        'detalles_sin_asignar' => array_values($sinAsignar),
                    ], 422);
                }
            }

            return response()->json([
                'success'    => true,
                'message'    => 'División de cuenta calculada',
                'orden_id'   => $orden->id,
                'folio'      => $orden->folio,
                'tipo_orden' => $orden->tipo_orden,
                'total'      => $totalOrden,
                'total_fmt'  => '$' . number_format($totalOrden, 2),
                'metodo'     => $request->metodo,
                'cuentas'    => $cuentas,
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al dividir cuenta', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resumen de órdenes
     */
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
                    'por_tipo' => [
                        'local'    => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'local')->count(),
                        'pickup'   => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'pickup')->count(),
                        'delivery' => Orden::where('restaurante_id', $rid)->where('tipo_orden', 'delivery')->count(),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener resumen', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Actualizar estado de preparación por estación (cocina, barra, postres)
     */
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

            $updateData = ['estado_preparacion' => $request->estado_preparacion];
            if ($request->estado_preparacion === 'EN_PREPARACION') {
                $updateData['en_preparacion_at'] = now();
            } elseif ($request->estado_preparacion === 'LISTO') {
                $updateData['listo_at'] = now();
            }

            OrdenDetalle::whereIn('id', $request->detalles)
                ->where('orden_id', $orden->id)
                ->update($updateData);

            $orden->verificarYActualizarEstadoGlobal();

            return response()->json([
                'success' => true,
                'message' => 'Estado de preparación actualizado',
                'data'    => [
                    'orden_id'           => $orden->id,
                    'nuevo_estado_orden' => $orden->estado,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Obtener órdenes filtradas por tipo (local, pickup, delivery)
     * GET /api/ordenes/por-tipo?tipo=delivery
     */
    public function porTipo(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $request->validate([
                'tipo'   => 'required|in:local,pickup,delivery',
                'estado' => 'nullable|string',
                'fecha'  => 'nullable|date',
            ]);

            $restauranteActivo = app('restaurante_activo');

            $query = Orden::with([
                    'usuario:id,name',
                    'detalles' => function($q) { $q->withTrashed()->with('producto.categoria'); },
                    'cliente',
                ])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('tipo_orden', $request->tipo);

            if ($request->filled('estado')) {
                $query->where('estado', $request->estado);
            }
            if ($request->filled('fecha')) {
                $query->whereDate('created_at', $request->fecha);
            }

            $ordenes = $query->orderBy('created_at', 'desc')->get();

            $stats = [
                'total_ordenes'   => $ordenes->count(),
                'total_ventas'    => $ordenes->where('estado', 'CERRADA')->sum('total'),
                'ordenes_activas' => $ordenes->whereNotIn('estado', ['CERRADA', 'CANCELADA'])->count(),
                'promedio_ticket' => $ordenes->where('estado', 'CERRADA')->avg('total') ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'tipo'         => $request->tipo,
                    'tipo_texto'   => Orden::$tiposOrden[$request->tipo] ?? ucfirst($request->tipo),
                    'estadisticas' => $stats,
                    'ordenes'      => $ordenes->map(fn($orden) => $this->transformarOrden($orden)),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Restaurar stock al cancelar una orden
     */
    private function restaurarStockOrden(Orden $orden)
    {
        foreach ($orden->detalles as $detalle) {
            // Solo restaurar si el producto NO había iniciado preparación (estaba pendiente)
            if (in_array($detalle->estado_preparacion, ['PENDIENTE']) || empty($detalle->estado_preparacion)) {
                \App\Helpers\StockHelper::restaurarStock($detalle, $detalle->cantidad, $orden->usuario_id);
            }
        }
    }

    /**
     * Transformar orden para respuesta JSON
     */
    private function transformarOrden(Orden $orden): array
    {
        $u = $orden->usuario ?? $orden->user ?? null;

        return [
            'id'                     => $orden->id,
            'restaurante_id'         => $orden->restaurante_id,
            'folio'                  => $orden->folio,
            'tipo_orden'             => $orden->tipo_orden ?? 'local',
            'tipo_orden_texto'       => $orden->tipo_orden_texto ?? 'Local',
            'tipo_orden_badge'       => $orden->tipo_orden_badge ?? ['color' => 'blue', 'icono' => '🏠', 'texto' => 'Local'],
            'user'                   => $u ? [
                'id'       => $u->id,
                'name'     => $u->name,
                'username' => $u->username,
                'email'    => $u->email ?? null,
            ] : null,
            'cliente'                => $orden->cliente ? [
                'id'       => $orden->cliente->id,
                'nombre'   => $orden->cliente->nombre,
                'telefono' => $orden->cliente->telefono,
            ] : null,
            'estado'                 => $orden->estado,
            'estado_texto'           => $orden->estado_texto,
            'estado_color'           => $orden->estado_color,
            'total'                  => (float) $orden->total,
            'total_formateado'       => '$' . number_format($orden->total, 2),
            'mesa'                   => $orden->mesa,
            'comensales'             => $orden->detalles->pluck('nom_comensal')->unique()->filter()->values(),
            'direccion_entrega'      => $orden->direccion_entrega,
            'telefono_contacto'      => $orden->telefono_contacto,
            'costo_envio'            => (float) ($orden->costo_envio ?? 0),
            'costo_envio_formateado' => '$' . number_format($orden->costo_envio ?? 0, 2),
            'tiempo_estimado_entrega'=> $orden->tiempo_estimado_entrega,
            'metodo_pago'            => $orden->metodo_pago,
            'propina'                => (float) ($orden->propina ?? 0),
            'notas'                  => $orden->notas,
            'cantidad_productos'     => $orden->detalles->sum('cantidad'),
            'productos_unicos'       => $orden->detalles->count(),
            'detalles'               => $orden->detalles->map(fn($d) => [
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
                'mesa'                => $orden->mesa,
                'precio_unitario'     => (float) $d->precio_unitario,
                'precio_formateado'   => '$' . number_format($d->precio_unitario, 2),
                'subtotal'            => (float) $d->subtotal,
                'subtotal_formateado' => '$' . number_format($d->subtotal, 2),
                'notas'               => $d->notas ?? null,
                'comensal'            => $d->nom_comensal ?? null,
                'comensal_id'         => $d->comensal_id ?? null,
                'estado_preparacion'  => $d->estado_preparacion ?? 'PENDIENTE',
                'minutos_produccion'  => (float) ($d->producto->minutos_produccion ?? 0),
                'cancelado'           => $d->trashed(),
                'motivo_cancelacion'  => $d->motivo_cancelacion,
                'usuario_cancelo'     => $d->usuarioCancelo ? [
                    'id'   => $d->usuarioCancelo->id,
                    'name' => $d->usuarioCancelo->name
                ] : null,
                'created_at'          => $d->created_at?->format('Y-m-d H:i:s'),
            ]),
            'created_at'             => $orden->created_at,
            'created_at_formateado'  => $orden->created_at_formateado,
            'created_at_humano'      => $orden->created_at_humano,
            'updated_at'             => $orden->updated_at,
            'updated_at_formateado'  => $orden->updated_at?->format('d/m/Y H:i'),
        ];
    }
    /**
     * Obtener conteo de pedidos pendientes por estación
     */
    public function pendientesConteo(Request $request)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            
            $detalles = DB::table('orden_detalles')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->join('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->where('ordenes.restaurante_id', $restauranteActivo->id)
                ->whereIn('orden_detalles.estado_preparacion', ['PENDIENTE', 'EN_PREPARACION'])
                ->whereIn('ordenes.estado', ['ABIERTA', 'POR_PREPARAR', 'EN_PREPARACION', 'LISTA'])
                ->select('categorias.nombre as categoria_nombre')
                ->get();

            $res = ['cocina' => 0, 'barra' => 0, 'postres' => 0];

            foreach ($detalles as $d) {
                $nom = strtolower($d->categoria_nombre ?? '');
                
                // Lógica de Barra
                if (strpos($nom, 'barra') !== false || strpos($nom, 'bebida') !== false || strpos($nom, 'refresco') !== false || strpos($nom, 'fria') !== false) {
                    $res['barra']++;
                    continue;
                }
                
                // Lógica de Postres
                if (strpos($nom, 'postre') !== false || strpos($nom, 'dulce') !== false || strpos($nom, 'helado') !== false || strpos($nom, 'pastel') !== false) {
                    $res['postres']++;
                    continue;
                }
                
                // Por defecto Cocina
                $res['cocina']++;
            }

            return response()->json([
                'success' => true,
                'data'    => $res
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}