<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use App\Models\Ingrediente;
use App\Models\IngredienteMovimiento;
use App\Http\Requests\StoreOrdenDetalleRequest;
use App\Http\Requests\UpdateOrdenDetalleRequest;
use App\Http\Requests\UpdateEstadoEstacionRequest;
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
            $detalles = OrdenDetalle::with('producto.categoria')
                ->where('orden_id', $orden->id)
                ->paginate($perPage);

            $detallesData = $detalles->map(fn($detalle) => [
                'id'      => $detalle->id,
                'producto' => $detalle->producto ? [
                    'id'           => $detalle->producto->id,
                    'nombre'       => $detalle->producto->nombre,
                    'descripcion'  => $detalle->producto->descripcion,
                    'activo'       => $detalle->producto->activo,
                    'categoria_id' => $detalle->producto->categoria_id,
                    'categoria'    => $detalle->producto->categoria?->nombre,
                ] : [
                    'id'           => null,
                    'nombre'       => 'Producto eliminado',
                    'descripcion'  => null,
                    'activo'       => false,
                    'categoria_id' => null,
                    'categoria'    => null,
                ],
                'cantidad'            => $detalle->cantidad,
                'mesa'                => $orden->mesa,
                'comensal'            => $detalle->nom_comensal,
                'comensal_id'         => $detalle->comensal_id,
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
                        'mesa'            => $orden->mesa,
                        'comensales'      => $orden->detalles->pluck('nom_comensal')->unique()->filter()->values(),
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
    public function store(StoreOrdenDetalleRequest $request, $ordenId)
    {

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
                // Verificar stock para la cantidad adicional
                if ($producto->ingredientes->isNotEmpty()) {
                    foreach ($producto->ingredientes as $ingrediente) {
                        $necesario = (float) $ingrediente->pivot->cantidad * $request->cantidad;
                        if ($ingrediente->stock_actual < $necesario) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Stock insuficiente: {$ingrediente->nombre}",
                            ], 422);
                        }
                    }
                } else {
                    if ($producto->stock < $request->cantidad) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Stock insuficiente: {$producto->nombre}",
                        ], 422);
                    }
                }

                // Actualizar cantidad existente
                $nuevaCantidad = $detalleExistente->cantidad + $request->cantidad;
                $nuevoSubtotal = $producto->precio * $nuevaCantidad;

                $orden->total -= $detalleExistente->subtotal;

                $detalleExistente->update([
                    'cantidad' => $nuevaCantidad,
                    'subtotal' => $nuevoSubtotal,
                ]);

                $orden->total += $nuevoSubtotal;
                $orden->save();

                \App\Helpers\StockHelper::descontarStock($detalleExistente, $request->cantidad, $user->id);

                $detalle = $detalleExistente;
                $mensaje = "Cantidad actualizada para {$producto->nombre}";

            } else {
                // Producto nuevo en la orden — Verificar stock sin descontar todavía
                $subtotal = $producto->precio * $request->cantidad;

                if ($producto->ingredientes->isNotEmpty()) {
                    foreach ($producto->ingredientes as $ingrediente) {
                        $necesario = (float) $ingrediente->pivot->cantidad * $request->cantidad;
                        if ($ingrediente->stock_actual < $necesario) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Stock insuficiente: {$ingrediente->nombre}",
                            ], 422);
                        }
                    }
                } else {
                    if ($producto->stock < $request->cantidad) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Stock insuficiente: {$producto->nombre}",
                        ], 422);
                    }
                }

                $detalle = OrdenDetalle::create([
                    'orden_id'        => $orden->id,
                    'producto_id'     => $producto->id,
                    'cantidad'        => $request->cantidad,
                    'precio_unitario' => $producto->precio,
                    'subtotal'        => $subtotal,
                    'nom_comensal'    => $request->comensal ?? $request->nom_comensal,
                    'comensal_id'     => $request->comensal_id,
                ]);

                $orden->total += $subtotal;
                $orden->save();

                \App\Helpers\StockHelper::descontarStock($detalle, $request->cantidad, $user->id);

                $mensaje = 'Producto agregado a la orden';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data'    => [
                    'detalle' => [
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
    public function update(UpdateOrdenDetalleRequest $request, $ordenId, $detalleId)
    {

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

            $diferencia = $request->cantidad - $detalle->cantidad;
            $producto = $detalle->producto;

            if ($diferencia > 0) {
                // Verificar stock para el incremento
                if ($producto && $producto->ingredientes->isNotEmpty()) {
                    foreach ($producto->ingredientes as $ingrediente) {
                        $necesario = (float) $ingrediente->pivot->cantidad * $diferencia;
                        if ($ingrediente->stock_actual < $necesario) {
                            DB::rollBack();
                            return response()->json([
                                'success' => false,
                                'message' => "Stock insuficiente: {$ingrediente->nombre}",
                            ], 422);
                        }
                    }
                } elseif ($producto) {
                    if ($producto->stock < $diferencia) {
                        DB::rollBack();
                        return response()->json([
                            'success' => false,
                            'message' => "Stock insuficiente: {$producto->nombre}",
                        ], 422);
                    }
                }

                // Descontar
                \App\Helpers\StockHelper::descontarStock($detalle, $diferencia, $user->id);
            } elseif ($diferencia < 0) {
                // Si disminuye y no ha iniciado preparación, restauramos el stock
                if (in_array($detalle->estado_preparacion, ['PENDIENTE']) || empty($detalle->estado_preparacion)) {
                    \App\Helpers\StockHelper::restaurarStock($detalle, abs($diferencia), $user->id);
                }
            }

            $orden->total  -= $detalle->subtotal;
            $nuevoSubtotal  = $detalle->precio_unitario * $request->cantidad;

            $detalle->update([
                'cantidad' => $request->cantidad,
                'notas'    => $request->notas,
                'subtotal' => $nuevoSubtotal,
                'nom_comensal' => $request->comensal ?? $request->nom_comensal ?? $detalle->nom_comensal,
                'comensal_id'  => $request->comensal_id ?? $detalle->comensal_id,
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

            if (!$user->hasPermission('ELIMINAR_ORDENES') && !$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para eliminar detalles'], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Permitir eliminar en casi cualquier estado excepto CERRADA o PAGADA
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereNotIn('estado', ['CERRADA', 'PAGADA', 'CANCELADA'])
                ->firstOrFail();

            $detalle = OrdenDetalle::with('producto.ingredientes')
                ->where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            $productoNombre = $detalle->producto->nombre ?? 'Producto';
            $cantidad       = $detalle->cantidad;
            $motivo         = $request->get('motivo', 'Cancelación sin motivo especificado');

            // 🔄 DEVOLUCIÓN DE STOCK: Solo si NO había iniciado preparación (estaba pendiente)
            if (in_array($detalle->estado_preparacion, ['PENDIENTE']) || empty($detalle->estado_preparacion)) {
                \App\Helpers\StockHelper::restaurarStock($detalle, $detalle->cantidad, $user->id);
            }

            // Registrar motivo y usuario antes de borrar suavemente
            $detalle->update([
                'motivo_cancelacion' => $motivo,
                'usuario_cancelo_id' => $user->id
            ]);

            $detalle->delete(); // Soft delete

            // Recalcular total de la orden
            $orden->recalcularTotal();
            $orden->verificarYActualizarEstadoGlobal();

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

            $detalle = OrdenDetalle::with('producto.categoria')
                ->where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'      => $detalle->id,
                    'producto'=> $detalle->producto ? [
                        'id'           => $detalle->producto->id,
                        'nombre'       => $detalle->producto->nombre,
                        'descripcion'  => $detalle->producto->descripcion,
                        'precio'       => (float) $detalle->producto->precio,
                        'categoria_id' => $detalle->producto->categoria_id,
                        'categoria'    => $detalle->producto->categoria?->nombre ?? null,
                    ] : null,
                    'mesa'                => $orden->mesa,
                    'comensal'            => $detalle->nom_comensal,
                    'comensal_id'         => $detalle->comensal_id,
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
            'detalles.*.cantidad' => 'required|numeric|min:0.1|max:100',
            'detalles.*.comensal' => 'nullable|string|max:100',
            'detalles.*.comensal_id' => 'nullable|integer',
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
                    'nom_comensal' => $item['comensal'] ?? $item['nom_comensal'] ?? $detalle->nom_comensal,
                    'comensal_id'  => $item['comensal_id'] ?? $detalle->comensal_id,
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

   public function actualizarEstadoPorEstacion(UpdateEstadoEstacionRequest $request, $ordenId)
    {

        try {
            $user        = $request->user();
            $estacion    = strtolower($request->estacion);
            $nuevoEstado = strtoupper($request->estado);

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->firstOrFail();

            DB::beginTransaction();

            $detalles = OrdenDetalle::with(['producto.ingredientes'])
                ->where('orden_id', $orden->id)
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

            // Procesar ingredientes deseleccionados/excluidos para devolverlos al inventario
            if ($nuevoEstado === 'EN_PREPARACION' && $request->filled('ingredientes_excluidos')) {
                foreach ($request->ingredientes_excluidos as $excluido) {
                    $prodId = $excluido['producto_id'];
                    $ingId  = $excluido['ingrediente_id'];

                    // Buscar el detalle correspondiente
                    $detalle = $detalles->firstWhere('producto_id', $prodId);
                    if ($detalle) {
                        $producto = $detalle->producto;
                        if ($producto) {
                            $ingrediente = $producto->ingredientes->firstWhere('id', $ingId);
                            if ($ingrediente) {
                                $cantidadARestaurar = (float) $ingrediente->pivot->cantidad * (float) $detalle->cantidad;
                                $stockAnterior      = $ingrediente->stock_actual;
                                $stockNuevo         = $stockAnterior + $cantidadARestaurar;

                                // Incrementar stock
                                \App\Models\Ingrediente::where('id', $ingrediente->id)
                                    ->increment('stock_actual', $cantidadARestaurar);

                                // Registrar movimiento
                                \App\Models\IngredienteMovimiento::create([
                                    'ingrediente_id'      => $ingrediente->id,
                                    'producto_id'         => $producto->id,
                                    'orden_id'            => $orden->id,
                                    'user_id'             => $user->id,
                                    'tipo'                => \App\Models\IngredienteMovimiento::TIPO_ENTRADA,
                                    'cantidad_anterior'   => $stockAnterior,
                                    'cantidad_movimiento' => $cantidadARestaurar,
                                    'cantidad_nueva'      => $stockNuevo,
                                    'motivo'              => "Exclusión receta estación {$estacion} - Orden #{$orden->id}",
                                ]);

                                $producto->recalcularStockDesdeIngredientes();
                            }
                        }
                    }
                }
            }

            foreach ($detalles as $detalle) {
                $estadoAnterior = $detalle->estado_preparacion;

                $updateData = ['estado_preparacion' => $nuevoEstado];
                if ($nuevoEstado === 'EN_PREPARACION' && !$detalle->en_preparacion_at) {
                    $updateData['en_preparacion_at'] = now();
                } elseif ($nuevoEstado === 'LISTO' && !$detalle->listo_at) {
                    $updateData['listo_at'] = now();
                }
                $detalle->update($updateData);
            }

            if ($nuevoEstado === 'EN_PREPARACION' && $orden->estado === 'POR_PREPARAR') {
                $orden->update(['estado' => 'EN_PREPARACION']);
            }

            DB::commit();

            // Broadcast opcional
            try {
                $orden->load(['detalles.producto.categoria', 'usuario:id,name']);
                broadcast(new \App\Events\OrdenActualizada($orden, 'estado_cambiado', $restauranteActivo->id));
            } catch (\Exception $be) {
                \Log::warning('Broadcast error: ' . $be->getMessage());
            }

            return response()->json([
                'success' => true,
                'message' => "Estación {$estacion} actualizada a {$nuevoEstado}",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}