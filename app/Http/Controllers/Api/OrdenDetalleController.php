<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Ingrediente;
use App\Models\IngredienteMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdenDetalleController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════════
    // INDEX — Listar detalles de una orden
    // ═════════════════════════════════════════════════════════════════════════
    public function index(Request $request, $ordenId)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para ver detalles de órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->firstOrFail();

            $perPage  = min($request->get('per_page', 20), 50);
            $detalles = OrdenDetalle::with('producto')
                ->where('orden_id', $orden->id)
                ->paginate($perPage);

            $detallesData = $detalles->map(fn($detalle) => [
                'id'      => $detalle->id,
                'producto' => $detalle->producto ? [
                    'id'          => $detalle->producto->id,
                    'nombre'      => $detalle->producto->nombre,
                    'descripcion' => $detalle->producto->descripcion,
                    'activo'      => $detalle->producto->activo,
                ] : [
                    'id'          => null,
                    'nombre'      => 'Producto eliminado',
                    'descripcion' => null,
                    'activo'      => false,
                ],
                'cantidad'            => $detalle->cantidad,
                'precio_unitario'     => (float) $detalle->precio_unitario,
                'precio_formateado'   => '$' . number_format($detalle->precio_unitario, 2),
                'subtotal'            => (float) $detalle->subtotal,
                'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                'created_at'          => $detalle->created_at,
                'updated_at'          => $detalle->updated_at,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Detalles obtenidos correctamente',
                'data'    => [
                    'orden' => [
                        'id'              => $orden->id,
                        'folio'           => 'ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT),
                        'estado'          => $orden->estado,
                        'total'           => (float) $orden->total,
                        'total_formateado'=> '$' . number_format($orden->total, 2),
                    ],
                    'detalles' => $detallesData,
                    'resumen'  => [
                        'total_productos'  => $detalles->sum('cantidad'),
                        'productos_unicos' => $detalles->count(),
                        'subtotal_general' => (float) $orden->total,
                    ],
                ],
                'pagination' => [
                    'current_page' => $detalles->currentPage(),
                    'per_page'     => $detalles->perPage(),
                    'total'        => $detalles->total(),
                    'last_page'    => $detalles->lastPage(),
                    'from'         => $detalles->firstItem(),
                    'to'           => $detalles->lastItem(),
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener detalles', 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // STORE — Agregar producto a una orden
    // ═════════════════════════════════════════════════════════════════════════
    public function store(Request $request, $ordenId)
    {
        $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'cantidad'    => 'required|integer|min:1|max:100',
        ]);

        try {
            $user = $request->user();

            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para modificar órdenes'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            $producto = Producto::with('ingredientes')
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $request->producto_id)
                ->where('activo', true)
                ->firstOrFail();

            $detalleExistente = OrdenDetalle::where('orden_id', $orden->id)
                ->where('producto_id', $producto->id)
                ->first();

            DB::beginTransaction();

            if ($detalleExistente) {
                // Actualizar cantidad existente — sin descontar stock de nuevo
                $nuevaCantidad = $detalleExistente->cantidad + $request->cantidad;
                $nuevoSubtotal = $producto->precio * $nuevaCantidad;

                $orden->total -= $detalleExistente->subtotal;

                $detalleExistente->update([
                    'cantidad' => $nuevaCantidad,
                    'subtotal' => $nuevoSubtotal,
                ]);

                $orden->total += $nuevoSubtotal;
                $orden->save();

                $detalle = $detalleExistente;
                $mensaje = "Cantidad actualizada para {$producto->nombre}";

            } else {
                // Producto nuevo en la orden — verificar y descontar stock
                $subtotal = $producto->precio * $request->cantidad;

                if ($producto->ingredientes->isNotEmpty()) {
                    // Producto con receta — verificar ingredientes
                    foreach ($producto->ingredientes as $ingrediente) {
                        $cantidadNecesaria = (float) $ingrediente->pivot->cantidad * $request->cantidad;

                        if ($ingrediente->stock_actual < $cantidadNecesaria) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Stock insuficiente para ingrediente: {$ingrediente->nombre}. Disponible: {$ingrediente->stock_actual}",
                            ], 422);
                        }
                    }

                    // Descontar stock de ingredientes y registrar movimientos
                    foreach ($producto->ingredientes as $ingrediente) {
                        $cantidadNecesaria  = (float) $ingrediente->pivot->cantidad * $request->cantidad;
                        $stockAnterior      = $ingrediente->stock_actual;
                        $stockNuevo         = $stockAnterior - $cantidadNecesaria;

                        Ingrediente::where('id', $ingrediente->id)
                            ->decrement('stock_actual', $cantidadNecesaria);

                        IngredienteMovimiento::create([
                            'ingrediente_id'      => $ingrediente->id,
                            'producto_id'         => $producto->id,
                            'orden_id'            => $orden->id,
                            'user_id'             => $user->id,
                            'tipo'                => IngredienteMovimiento::TIPO_SALIDA,
                            'cantidad_anterior'   => $stockAnterior,
                            'cantidad_movimiento' => $cantidadNecesaria,
                            'cantidad_nueva'      => $stockNuevo,
                            'motivo'              => "Venta - Orden #{$orden->id}",
                        ]);
                    }

                    $producto->recalcularStockDesdeIngredientes();

                } else {
                    // Producto sin receta — stock directo
                    if ($producto->stock < $request->cantidad) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Stock insuficiente para {$producto->nombre}. Disponible: {$producto->stock}",
                        ], 422);
                    }

                    Producto::where('id', $producto->id)->decrement('stock', $request->cantidad);
                }

                $detalle = OrdenDetalle::create([
                    'orden_id'        => $orden->id,
                    'producto_id'     => $producto->id,
                    'cantidad'        => $request->cantidad,
                    'precio_unitario' => $producto->precio,
                    'subtotal'        => $subtotal,
                ]);

                $orden->total += $subtotal;
                $orden->save();

                $mensaje = 'Producto agregado a la orden';
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('AGREGAR_PRODUCTO_ORDEN', 'orden_detalles', $detalle->id,
                    "Agregado {$producto->nombre} x{$request->cantidad} a orden #{$orden->id}");
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data'    => [
                    'detalle' => [
                        'id'                  => $detalle->id,
                        'producto_id'         => $producto->id,
                        'producto_nombre'     => $producto->nombre,
                        'cantidad'            => $detalle->cantidad,
                        'precio_unitario'     => (float) $producto->precio,
                        'subtotal'            => (float) $detalle->subtotal,
                        'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                    ],
                    'orden' => [
                        'id'              => $orden->id,
                        'total'           => (float) $orden->total,
                        'total_formateado'=> '$' . number_format($orden->total, 2),
                    ],
                ],
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Orden o producto no encontrado'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al agregar producto', 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // UPDATE — Actualizar cantidad de un detalle
    // ═════════════════════════════════════════════════════════════════════════
    public function update(Request $request, $ordenId, $detalleId)
    {
        $request->validate([
            'cantidad' => 'required|integer|min:1|max:100',
            'notas'    => 'nullable|string|max:255',
        ]);

        try {
            $user = $request->user();

            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para modificar detalles'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            $detalle = OrdenDetalle::where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            $orden->total  -= $detalle->subtotal;
            $nuevoSubtotal  = $detalle->precio_unitario * $request->cantidad;

            $detalle->update([
                'cantidad' => $request->cantidad,
                'notas'    => $request->notas,
                'subtotal' => $nuevoSubtotal,
            ]);

            $orden->total += $nuevoSubtotal;
            $orden->save();

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('EDITAR_DETALLE_ORDEN', 'orden_detalles', $detalle->id,
                    "Actualizado cantidad a {$request->cantidad} en orden #{$orden->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Detalle actualizado correctamente',
                'data'    => [
                    'detalle' => [
                        'id'                  => $detalle->id,
                        'producto_id'         => $detalle->producto_id,
                        'cantidad'            => $detalle->cantidad,
                        'subtotal'            => (float) $detalle->subtotal,
                        'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                    ],
                    'orden' => [
                        'id'              => $orden->id,
                        'total'           => (float) $orden->total,
                        'total_formateado'=> '$' . number_format($orden->total, 2),
                    ],
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Orden o detalle no encontrado'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar detalle', 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // DESTROY — Eliminar un detalle
    // ═════════════════════════════════════════════════════════════════════════
    public function destroy(Request $request, $ordenId, $detalleId)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('ELIMINAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para eliminar detalles'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            $detalle = OrdenDetalle::with('producto.ingredientes')
                ->where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            $productoNombre = $detalle->producto->nombre ?? 'Producto';
            $cantidad       = $detalle->cantidad;

            $orden->total -= $detalle->subtotal;
            $orden->save();

            $detalle->delete();

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('ELIMINAR_DETALLE_ORDEN', 'orden_detalles', $detalleId,
                    "Eliminado {$productoNombre} x{$cantidad} de orden #{$orden->id}");
            }

            return response()->json([
                'success' => true,
                'message' => 'Detalle eliminado correctamente',
                'data'    => [
                    'orden' => [
                        'id'              => $orden->id,
                        'total'           => (float) $orden->total,
                        'total_formateado'=> '$' . number_format($orden->total, 2),
                    ],
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Orden o detalle no encontrado'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al eliminar detalle', 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // SHOW — Obtener un detalle específico
    // ═════════════════════════════════════════════════════════════════════════
    public function show(Request $request, $ordenId, $detalleId)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->firstOrFail();

            $detalle = OrdenDetalle::with('producto')
                ->where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'      => $detalle->id,
                    'producto'=> $detalle->producto ? [
                        'id'          => $detalle->producto->id,
                        'nombre'      => $detalle->producto->nombre,
                        'descripcion' => $detalle->producto->descripcion,
                        'precio'      => (float) $detalle->producto->precio,
                    ] : null,
                    'cantidad'            => $detalle->cantidad,
                    'precio_unitario'     => (float) $detalle->precio_unitario,
                    'precio_formateado'   => '$' . number_format($detalle->precio_unitario, 2),
                    'subtotal'            => (float) $detalle->subtotal,
                    'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                    'created_at'          => $detalle->created_at,
                    'updated_at'          => $detalle->updated_at,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Detalle no encontrado'], 404);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // UPDATE MULTIPLE — Actualizar varios detalles a la vez
    // ═════════════════════════════════════════════════════════════════════════
    public function updateMultiple(Request $request, $ordenId)
    {
        $request->validate([
            'detalles'            => 'required|array|min:1',
            'detalles.*.id'       => 'required|exists:orden_detalles,id',
            'detalles.*.cantidad' => 'required|integer|min:1|max:100',
        ]);

        try {
            $user = $request->user();

            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            DB::beginTransaction();

            // FIX: Incluir propina y costo_envio al recalcular total
            $nuevoTotal = 0;

            foreach ($request->detalles as $item) {
                $detalle = OrdenDetalle::where('orden_id', $orden->id)
                    ->where('id', $item['id'])
                    ->firstOrFail();

                $nuevoSubtotal = $detalle->precio_unitario * $item['cantidad'];

                $detalle->update([
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $nuevoSubtotal,
                ]);

                $nuevoTotal += $nuevoSubtotal;
            }

            // Preservar propina y costo_envio en el total
            $nuevoTotal += (float) ($orden->propina ?? 0);
            $nuevoTotal += (float) ($orden->costo_envio ?? 0);

            $orden->total = $nuevoTotal;
            $orden->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detalles actualizados correctamente',
                'data'    => [
                    'orden' => [
                        'id'              => $orden->id,
                        'total'           => (float) $orden->total,
                        'total_formateado'=> '$' . number_format($orden->total, 2),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar detalles', 'error' => $e->getMessage()], 500);
        }
    }

    // ═════════════════════════════════════════════════════════════════════════
    // ACTUALIZAR ESTADO POR ESTACIÓN (cocina, barra, postres)
    // FIX: Líneas de sintaxis incompletas corregidas + transacción añadida
    // ═════════════════════════════════════════════════════════════════════════
    public function actualizarEstadoPorEstacion(Request $request, $ordenId)
    {
        $request->validate([
            'estacion' => 'required|string|in:cocina,barra,postres',
            'estado'   => 'required|string|in:PENDIENTE,EN_PREPARACION,LISTO',
        ]);

        try {
            $user        = $request->user();
            $estacion    = strtolower($request->estacion);
            $nuevoEstado = strtoupper($request->estado);

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->firstOrFail();

            // FIX: DB::beginTransaction() — línea estaba cortada en el original
            DB::beginTransaction();

            $detalles = OrdenDetalle::where('orden_id', $orden->id)
                ->whereHas('producto.categoria', function ($q) use ($estacion) {
                    $q->whereRaw('LOWER(nombre) = ?', [$estacion]);
                })
                ->get();

            if ($detalles->isEmpty()) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => "No hay productos de la estación: {$estacion}",
                ], 404);
            }

            foreach ($detalles as $detalle) {
                $estadoAnterior = $detalle->estado_preparacion;

                // Descontar inventario solo al pasar de PENDIENTE → EN_PREPARACION
                if ($nuevoEstado === 'EN_PREPARACION' && ($estadoAnterior === 'PENDIENTE' || empty($estadoAnterior))) {
                    $producto = $detalle->producto;

                    if ($producto && $producto->ingredientes->isNotEmpty()) {
                        foreach ($producto->ingredientes as $ingrediente) {
                            $cantidadADescontar = (float) $ingrediente->pivot->cantidad * (int) $detalle->cantidad;
                            $stockAnterior      = $ingrediente->stock_actual;
                            $stockNuevo         = $stockAnterior - $cantidadADescontar;

                            if ($stockNuevo < 0) {
                                DB::rollBack();
                                return response()->json([
                                    'success' => false,
                                    'message' => "Stock insuficiente para ingrediente: {$ingrediente->nombre}",
                                ], 422);
                            }

                            Ingrediente::where('id', $ingrediente->id)
                                ->decrement('stock_actual', $cantidadADescontar);

                            IngredienteMovimiento::create([
                                'ingrediente_id'      => $ingrediente->id,
                                'producto_id'         => $producto->id,
                                'orden_id'            => $orden->id,
                                'user_id'             => $user->id,
                                'tipo'                => IngredienteMovimiento::TIPO_SALIDA,
                                'cantidad_anterior'   => $stockAnterior,
                                'cantidad_movimiento' => $cantidadADescontar,
                                'cantidad_nueva'      => $stockNuevo,
                                'motivo'              => "Preparación estación {$estacion} - Orden #{$orden->id}",
                            ]);
                        }

                        $producto->recalcularStockDesdeIngredientes();

                    } elseif ($producto) {
                        if ($producto->stock < $detalle->cantidad) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Stock insuficiente para: {$producto->nombre}",
                            ], 422);
                        }

                        Producto::where('id', $producto->id)
                            ->decrement('stock', $detalle->cantidad);
                    }
                }

                $detalle->update(['estado_preparacion' => $nuevoEstado]);
            }

            if ($nuevoEstado === 'EN_PREPARACION' && $orden->estado === 'POR_PREPARAR') {
                $orden->update(['estado' => 'EN_PREPARACION']);
            }

            // FIX: DB::commit() — línea estaba cortada en el original
            DB::commit();

            try {
                $orden->load(['detalles.producto.categoria', 'usuario:id,name']);
                broadcast(new \App\Events\OrdenActualizada($orden, 'estado_cambiado', $restauranteActivo->id));
            } catch (\Exception $be) {
                \Log::warning('Broadcast actualizarEstadoPorEstacion: ' . $be->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Estación {$estacion} actualizada a {$nuevoEstado}",
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Orden no encontrada'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}