<?php
// app/Http/Controllers/Api/OrdenDetalleController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orden;
use App\Models\OrdenDetalle;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrdenDetalleController extends Controller
{
    /**
     * Listar detalles de una orden con paginación
     */
    public function index(Request $request, $ordenId)
    {
        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver detalles de órdenes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Verificar que la orden existe y pertenece al restaurante
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->firstOrFail();

            // Parámetros de paginación (por si hay muchos detalles)
            $perPage = $request->get('per_page', 20);
            $perPage = min($perPage, 50); // Máximo 50 por página

            // Obtener detalles con paginación
            $detalles = OrdenDetalle::with('producto')
                ->where('orden_id', $orden->id)
                ->paginate($perPage);

            // Transformar datos
            $detallesData = $detalles->map(function($detalle) {
                return [
                    'id' => $detalle->id,
                    'producto' => $detalle->producto ? [
                        'id' => $detalle->producto->id,
                        'nombre' => $detalle->producto->nombre,
                        'descripcion' => $detalle->producto->descripcion,
                        'activo' => $detalle->producto->activo
                    ] : [
                        'id' => null,
                        'nombre' => 'Producto eliminado',
                        'descripcion' => null,
                        'activo' => false
                    ],
                    'cantidad' => $detalle->cantidad,
                    'precio_unitario' => (float) $detalle->precio_unitario,
                    'precio_formateado' => '$' . number_format($detalle->precio_unitario, 2),
                    'subtotal' => (float) $detalle->subtotal,
                    'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                    'created_at' => $detalle->created_at,
                    'updated_at' => $detalle->updated_at
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Detalles obtenidos correctamente',
                'data' => [
                    'orden' => [
                        'id' => $orden->id,
                        'folio' => 'ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT),
                        'estado' => $orden->estado,
                        'total' => (float) $orden->total,
                        'total_formateado' => '$' . number_format($orden->total, 2)
                    ],
                    'detalles' => $detallesData,
                    'resumen' => [
                        'total_productos' => $detalles->sum('cantidad'),
                        'productos_unicos' => $detalles->count(),
                        'subtotal_general' => (float) $orden->total
                    ]
                ],
                'pagination' => [
                    'current_page' => $detalles->currentPage(),
                    'per_page' => $detalles->perPage(),
                    'total' => $detalles->total(),
                    'last_page' => $detalles->lastPage(),
                    'from' => $detalles->firstItem(),
                    'to' => $detalles->lastItem()
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Orden no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener detalles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Agregar producto a una orden
     */
    public function store(Request $request, $ordenId)
    {
        $request->validate([
            'producto_id' => 'required|exists:productos,id',
            'cantidad' => 'required|integer|min:1|max:100'
        ]);

        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('CREAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para modificar órdenes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Verificar orden
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            // Verificar producto
            $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $request->producto_id)
                ->where('activo', true)
                ->firstOrFail();

            // Verificar si el producto ya existe en la orden
            $detalleExistente = OrdenDetalle::where('orden_id', $orden->id)
                ->where('producto_id', $producto->id)
                ->first();

            DB::beginTransaction();

            if ($detalleExistente) {
                // Si ya existe, actualizar cantidad
                $nuevaCantidad = $detalleExistente->cantidad + $request->cantidad;
                $nuevoSubtotal = $producto->precio * $nuevaCantidad;
                
                // Actualizar total de la orden
                $orden->total -= $detalleExistente->subtotal;
                
                $detalleExistente->update([
                    'cantidad' => $nuevaCantidad,
                    'subtotal' => $nuevoSubtotal
                ]);
                
                $orden->total += $nuevoSubtotal;
                $orden->save();

                $detalle = $detalleExistente;
                
                $mensaje = "Cantidad actualizada para {$producto->nombre}";
            } else {
                // Si no existe, crear nuevo
                $subtotal = $producto->precio * $request->cantidad;

                $detalle = OrdenDetalle::create([
                    'orden_id' => $orden->id,
                    'producto_id' => $producto->id,
                    'cantidad' => $request->cantidad,
                    'precio_unitario' => $producto->precio,
                    'subtotal' => $subtotal
                ]);

                // Actualizar total de la orden
                $orden->total += $subtotal;
                $orden->save();
                
                $mensaje = 'Producto agregado a la orden';
            }

            DB::commit();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'AGREGAR_PRODUCTO_ORDEN',
                    'orden_detalles',
                    $detalle->id,
                    "Agregado {$producto->nombre} x{$request->cantidad} a orden #{$orden->id}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => [
                    'detalle' => [
                        'id' => $detalle->id,
                        'producto_id' => $producto->id,
                        'producto_nombre' => $producto->nombre,
                        'cantidad' => $detalle->cantidad,
                        'precio_unitario' => (float) $producto->precio,
                        'subtotal' => (float) $detalle->subtotal,
                        'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2)
                    ],
                    'orden' => [
                        'id' => $orden->id,
                        'total' => (float) $orden->total,
                        'total_formateado' => '$' . number_format($orden->total, 2)
                    ]
                ]
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Orden o producto no encontrado'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al agregar producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar cantidad de un detalle
     */
    public function update(Request $request, $ordenId, $detalleId)
    {
        $request->validate([
            'cantidad' => 'required|integer|min:1|max:100'
        ]);

        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para modificar detalles'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Verificar orden
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            // Verificar detalle
            $detalle = OrdenDetalle::where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            // Restar el subtotal anterior del total de la orden
            $orden->total -= $detalle->subtotal;

            // Calcular nuevo subtotal
            $nuevoSubtotal = $detalle->precio_unitario * $request->cantidad;
            
            // Actualizar detalle
            $detalle->update([
                'cantidad' => $request->cantidad,
                'subtotal' => $nuevoSubtotal
            ]);

            // Sumar nuevo subtotal al total
            $orden->total += $nuevoSubtotal;
            $orden->save();

            DB::commit();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_DETALLE_ORDEN',
                    'orden_detalles',
                    $detalle->id,
                    "Actualizado cantidad a {$request->cantidad} en orden #{$orden->id}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Detalle actualizado correctamente',
                'data' => [
                    'detalle' => [
                        'id' => $detalle->id,
                        'producto_id' => $detalle->producto_id,
                        'cantidad' => $detalle->cantidad,
                        'subtotal' => (float) $detalle->subtotal,
                        'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2)
                    ],
                    'orden' => [
                        'id' => $orden->id,
                        'total' => (float) $orden->total,
                        'total_formateado' => '$' . number_format($orden->total, 2)
                    ]
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Orden o detalle no encontrado'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar detalle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un detalle de la orden
     */
    public function destroy(Request $request, $ordenId, $detalleId)
    {
        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('ELIMINAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar detalles'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Verificar orden
            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            // Verificar detalle
            $detalle = OrdenDetalle::where('orden_id', $orden->id)
                ->where('id', $detalleId)
                ->firstOrFail();

            DB::beginTransaction();

            // Restar del total de la orden
            $orden->total -= $detalle->subtotal;
            $orden->save();

            // Guardar info para log
            $productoNombre = $detalle->producto->nombre ?? 'Producto';
            $cantidad = $detalle->cantidad;

            // Eliminar detalle
            $detalle->delete();

            DB::commit();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_DETALLE_ORDEN',
                    'orden_detalles',
                    $detalleId,
                    "Eliminado {$productoNombre} x{$cantidad} de orden #{$orden->id}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Detalle eliminado correctamente',
                'data' => [
                    'orden' => [
                        'id' => $orden->id,
                        'total' => (float) $orden->total,
                        'total_formateado' => '$' . number_format($orden->total, 2)
                    ]
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Orden o detalle no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar detalle',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener un detalle específico
     */
    public function show(Request $request, $ordenId, $detalleId)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
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
                'data' => [
                    'id' => $detalle->id,
                    'producto' => $detalle->producto ? [
                        'id' => $detalle->producto->id,
                        'nombre' => $detalle->producto->nombre,
                        'descripcion' => $detalle->producto->descripcion,
                        'precio' => (float) $detalle->producto->precio
                    ] : null,
                    'cantidad' => $detalle->cantidad,
                    'precio_unitario' => (float) $detalle->precio_unitario,
                    'precio_formateado' => '$' . number_format($detalle->precio_unitario, 2),
                    'subtotal' => (float) $detalle->subtotal,
                    'subtotal_formateado' => '$' . number_format($detalle->subtotal, 2),
                    'created_at' => $detalle->created_at,
                    'updated_at' => $detalle->updated_at
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Detalle no encontrado'
            ], 404);
        }
    }

    /**
     * Actualizar múltiples detalles a la vez
     */
    public function updateMultiple(Request $request, $ordenId)
    {
        $request->validate([
            'detalles' => 'required|array|min:1',
            'detalles.*.id' => 'required|exists:orden_detalles,id',
            'detalles.*.cantidad' => 'required|integer|min:1|max:100'
        ]);

        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_ORDENES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $orden = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $ordenId)
                ->whereIn('estado', ['ABIERTA', 'EN_PREPARACION'])
                ->firstOrFail();

            DB::beginTransaction();

            // Resetear total
            $orden->total = 0;

            foreach ($request->detalles as $item) {
                $detalle = OrdenDetalle::where('orden_id', $orden->id)
                    ->where('id', $item['id'])
                    ->firstOrFail();

                $nuevoSubtotal = $detalle->precio_unitario * $item['cantidad'];
                
                $detalle->update([
                    'cantidad' => $item['cantidad'],
                    'subtotal' => $nuevoSubtotal
                ]);

                $orden->total += $nuevoSubtotal;
            }

            $orden->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Detalles actualizados correctamente',
                'data' => [
                    'orden' => [
                        'id' => $orden->id,
                        'total' => (float) $orden->total,
                        'total_formateado' => '$' . number_format($orden->total, 2)
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar detalles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function actualizarEstadoPorEstacion(\Illuminate\Http\Request $request, $ordenId)
    {
        $request->validate([
            "estacion" => "required|string|in:cocina,barra,postres",
            "estado"   => "required|string|in:PENDIENTE,EN_PREPARACION,LISTO"
        ]);

        try {
            $user = $request->user();
            $estacion = strtolower($request->estacion);
            $nuevoEstado = strtoupper($request->estado);

            $restauranteActivo = app("restaurante_activo");
            $orden = \App\Models\Orden::where("restaurante_id", $restauranteActivo->id)
                ->where("id", $ordenId)
                ->firstOrFail();

            \Illuminate\Support\Facades\DB::beginTransaction();

            $detalles = \App\Models\OrdenDetalle::where("orden_id", $orden->id)
                ->whereHas("producto.categoria", function($q) use ($estacion) {
                    $q->whereRaw("LOWER(nombre) = ?", [$estacion]);
                })
                ->get();

            if ($detalles->isEmpty()) {
                return response()->json(["success" => false, "message" => "No hay productos de la estacion " . $estacion], 404);
            }

            foreach ($detalles as $detalle) {
                $detalle->update(["estado_preparacion" => $nuevoEstado]);
            }

            if ($nuevoEstado === "EN_PREPARACION" && $orden->estado === "POR_PREPARAR") {
                $orden->update(["estado" => "EN_PREPARACION"]);
            }

            \Illuminate\Support\Facades\DB::commit();

            try {
                $orden->load(["detalles.producto.categoria", "usuario:id,name"]);
                broadcast(new \App\Events\OrdenActualizada($orden, "estado_cambiado", $restauranteActivo->id));
            } catch (\Exception $e) {}

            return response()->json(["success" => true, "message" => "Estacion " . $estacion . " actualizada"]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(["success" => false, "error" => $e->getMessage()], 500);
        }
    }
}
