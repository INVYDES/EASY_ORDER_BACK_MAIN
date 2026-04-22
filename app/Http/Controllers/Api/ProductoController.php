<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Producto;
use App\Models\Categoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ProductoController extends Controller
{
    /**
     * Listar productos con paginación y filtros
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver productos'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Parámetros de paginación
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 50);
            $page = $request->get('page', 1);

            // Construir query base con relaciones
            $query = Producto::with(['categoria', 'ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id);

            // FILTROS
            if ($request->has('nombre') && !empty($request->nombre)) {
                $query->where('nombre', 'like', '%' . $request->nombre . '%');
            }

            if ($request->has('categoria_id') && !empty($request->categoria_id)) {
                $query->where('categoria_id', $request->categoria_id);
            }

            if ($request->has('descripcion') && !empty($request->descripcion)) {
                $query->where('descripcion', 'like', '%' . $request->descripcion . '%');
            }

            if ($request->has('activo')) {
                $activo = filter_var($request->activo, FILTER_VALIDATE_BOOLEAN);
                $query->where('activo', $activo);
            }

            if ($request->has('precio_min')) {
                $query->where('precio', '>=', $request->precio_min);
            }

            if ($request->has('precio_max')) {
                $query->where('precio', '<=', $request->precio_max);
            }

            if ($request->has('stock_min')) {
                $query->where('stock', '>=', $request->stock_min);
            }

            if ($request->has('stock_max')) {
                $query->where('stock', '<=', $request->stock_max);
            }

            if ($request->has('bajo_stock') && $request->bajo_stock) {
                $query->whereColumn('stock', '<=', 'stock_minimo')
                      ->where('stock', '>', 0);
            }

            if ($request->has('sin_stock') && $request->sin_stock) {
                $query->where('stock', '<=', 0);
            }

            if ($request->has('buscar') && !empty($request->buscar)) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('nombre', 'like', "%{$buscar}%")
                      ->orWhere('descripcion', 'like', "%{$buscar}%");
                });
            }

            if ($request->has('mas_vendidos')) {
                $query->withCount('ordenDetalles as ventas_count')
                      ->orderBy('ventas_count', 'desc');
            }

            $orderBy = $request->get('order_by', 'nombre');
            $orderDir = $request->get('order_dir', 'asc');
            
            $allowedOrderFields = ['id', 'nombre', 'precio', 'stock', 'activo', 'created_at', 'updated_at', 'ventas_count'];
            if (in_array($orderBy, $allowedOrderFields)) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            } else {
                $query->orderBy('nombre', 'asc');
            }

            $productos = $query->paginate($perPage, ['*'], 'page', $page);

            $productosData = $productos->map(function($producto) {
                $bajoStock = $producto->stock <= $producto->stock_minimo;
                
                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'descripcion' => $producto->descripcion,
                    
                    'categoria' => $producto->categoria ? [
                        'id' => $producto->categoria->id,
                        'nombre' => $producto->categoria->nombre,
                        'color' => $producto->categoria->color,
                        'icono' => $producto->categoria->icono ?? null
                    ] : null,
                    'categoria_id' => $producto->categoria_id,

                    'ingredientes' => $producto->ingredientes->map(function($ing) {
                        return [
                            'id' => $ing->id,
                            'nombre' => $ing->nombre,
                            'unidad' => $ing->unidad,
                            'stock_actual' => (float) $ing->stock_actual,
                            'stock_minimo' => (float) $ing->stock_minimo,
                            'costo_unitario' => (float) $ing->costo_unitario,
                            'cantidad_necesaria' => (float) ($ing->pivot->cantidad ?? 0)
                        ];
                    })->toArray(),
                    
                    'tiene_ingredientes' => $producto->ingredientes->isNotEmpty(),
                    'puede_prepararse' => $this->puedePrepararse($producto),
                    
                    'imagen' => $producto->imagen,
                    'imagen_url' => $producto->imagen_url,
                    
                    'precio' => (float) $producto->precio,
                    'precio_formateado' => '$' . number_format($producto->precio, 2),
                    
                    'stock' => (int) $producto->stock,
                    'stock_minimo' => (int) $producto->stock_minimo,
                    'bajo_stock' => $bajoStock,
                    'bajo_stock_texto' => $bajoStock ? 'Sí' : 'No',
                    'agotado' => $producto->stock <= 0,
                    'estado_stock' => $this->getEstadoStock($producto->stock, $producto->stock_minimo),
                    
                    'activo' => (bool) $producto->activo,
                    'activo_texto' => $producto->activo ? 'Activo' : 'Inactivo',
                    
                    'created_at' => $producto->created_at,
                    'created_at_formateado' => $producto->created_at ? $producto->created_at->format('d/m/Y H:i') : null,
                    'updated_at' => $producto->updated_at,
                    'updated_at_formateado' => $producto->updated_at ? $producto->updated_at->format('d/m/Y H:i') : null,
                    
                    'total_ventas' => $producto->ordenDetalles()->count(),
                    'cantidad_vendida' => (int) $producto->ordenDetalles()->sum('cantidad')
                ];
            });

            $estadisticas = [
                'total_productos' => Producto::where('restaurante_id', $restauranteActivo->id)->count(),
                'activos' => Producto::where('restaurante_id', $restauranteActivo->id)->where('activo', true)->count(),
                'inactivos' => Producto::where('restaurante_id', $restauranteActivo->id)->where('activo', false)->count(),
                
                'bajo_stock' => Producto::where('restaurante_id', $restauranteActivo->id)
                    ->whereColumn('stock', '<=', 'stock_minimo')
                    ->where('stock', '>', 0)
                    ->count(),
                'sin_stock' => Producto::where('restaurante_id', $restauranteActivo->id)
                    ->where('stock', '<=', 0)
                    ->count(),
                'stock_total' => (int) Producto::where('restaurante_id', $restauranteActivo->id)->sum('stock'),
                
                'precio_promedio' => (float) Producto::where('restaurante_id', $restauranteActivo->id)->avg('precio'),
                'precio_minimo' => (float) Producto::where('restaurante_id', $restauranteActivo->id)->min('precio'),
                'precio_maximo' => (float) Producto::where('restaurante_id', $restauranteActivo->id)->max('precio'),
                
                'productos_por_categoria' => $this->getProductosPorCategoria($restauranteActivo->id)
            ];

            return response()->json([
                'success' => true,
                'message' => 'Productos obtenidos correctamente',
                'data' => $productosData,
                'pagination' => [
                    'current_page' => $productos->currentPage(),
                    'per_page' => $productos->perPage(),
                    'total' => $productos->total(),
                    'last_page' => $productos->lastPage(),
                    'from' => $productos->firstItem(),
                    'to' => $productos->lastItem(),
                    'next_page_url' => $productos->nextPageUrl(),
                    'prev_page_url' => $productos->previousPageUrl(),
                    'has_more_pages' => $productos->hasMorePages()
                ],
                'filters' => [
                    'nombre' => $request->nombre ?? null,
                    'categoria_id' => $request->categoria_id ?? null,
                    'activo' => $request->activo ?? null,
                    'precio_min' => $request->precio_min ?? null,
                    'precio_max' => $request->precio_max ?? null,
                    'stock_min' => $request->stock_min ?? null,
                    'stock_max' => $request->stock_max ?? null,
                    'bajo_stock' => $request->bajo_stock ?? null,
                    'sin_stock' => $request->sin_stock ?? null,
                    'buscar' => $request->buscar ?? null,
                    'mas_vendidos' => $request->mas_vendidos ?? null
                ],
                'estadisticas' => $estadisticas
            ]);

        } catch (\Exception $e) {
            Log::error('Error en ProductoController@index: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener productos',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Mostrar un producto específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $producto = Producto::with(['categoria', 'ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $bajoStock = $producto->stock <= $producto->stock_minimo;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'descripcion' => $producto->descripcion,
                    
                    'categoria' => $producto->categoria ? [
                        'id' => $producto->categoria->id,
                        'nombre' => $producto->categoria->nombre,
                        'color' => $producto->categoria->color,
                        'icono' => $producto->categoria->icono
                    ] : null,
                    'categoria_id' => $producto->categoria_id,

                    'ingredientes' => $producto->ingredientes->map(function($ing) {
                        return [
                            'id' => $ing->id,
                            'nombre' => $ing->nombre,
                            'unidad' => $ing->unidad,
                            'stock_actual' => (float) $ing->stock_actual,
                            'stock_minimo' => (float) $ing->stock_minimo,
                            'costo_unitario' => (float) $ing->costo_unitario,
                            'cantidad_necesaria' => (float) ($ing->pivot->cantidad ?? 0)
                        ];
                    })->toArray(),
                    
                    'tiene_ingredientes' => $producto->ingredientes->isNotEmpty(),
                    'puede_prepararse' => $this->puedePrepararse($producto),
                    'unidades_posibles' => $this->calcularUnidadesPosibles($producto),

                    'imagen' => $producto->imagen,
                    'imagen_url' => $producto->imagen_url,
                    
                    'precio' => (float) $producto->precio,
                    'precio_formateado' => '$' . number_format($producto->precio, 2),
                    
                    'stock' => (int) $producto->stock,
                    'stock_minimo' => (int) $producto->stock_minimo,
                    'bajo_stock' => $bajoStock,
                    'agotado' => $producto->stock <= 0,
                    'estado_stock' => $this->getEstadoStock($producto->stock, $producto->stock_minimo),
                    
                    'activo' => (bool) $producto->activo,
                    'created_at' => $producto->created_at,
                    'created_at_formateado' => $producto->created_at ? $producto->created_at->format('d/m/Y H:i') : null,
                    'updated_at' => $producto->updated_at,
                    'updated_at_formateado' => $producto->updated_at ? $producto->updated_at->format('d/m/Y H:i') : null,
                    
                    'total_ventas' => $producto->ordenDetalles()->count(),
                    'cantidad_vendida' => (int) $producto->ordenDetalles()->sum('cantidad'),
                    'ultimas_ventas' => $producto->ordenDetalles()
                        ->with('orden')
                        ->latest()
                        ->limit(5)
                        ->get()
                        ->map(function($detalle) {
                            return [
                                'fecha' => $detalle->orden->created_at ? $detalle->orden->created_at->format('d/m/Y H:i') : null,
                                'cantidad' => $detalle->cantidad,
                                'subtotal' => (float) $detalle->subtotal,
                                'total_formateado' => '$' . number_format($detalle->subtotal, 2)
                            ];
                        })
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error en ProductoController@show: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener producto',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Crear un nuevo producto
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para crear productos'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $validator = Validator::make($request->all(), [
                'nombre' => 'required|string|max:150',
                'descripcion' => 'nullable|string|max:1000',
                'precio' => 'required|numeric|min:0|max:999999.99',
                'categoria_id' => 'nullable|exists:categorias,id',
                'stock' => 'nullable|integer|min:0',
                'stock_minimo' => 'nullable|integer|min:0',
                'activo' => 'nullable|boolean',
                'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'imagen_url' => 'nullable|url|max:500',
                'ingredientes' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $existente = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('nombre', $request->nombre)
                ->first();

            if ($existente) {
                return response()->json([
                    'success' => false,
                    'message' => 'Ya existe un producto con este nombre en el restaurante'
                ], 409);
            }

            DB::beginTransaction();

            $data = [
                'restaurante_id' => $restauranteActivo->id,
                'categoria_id' => $request->categoria_id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'precio' => $request->precio,
                'stock' => $request->stock ?? 0,
                'stock_minimo' => $request->stock_minimo ?? 5,
                'activo' => $request->has('activo') ? $request->activo : true
            ];

            if ($request->hasFile('imagen')) {
                $path = $request->file('imagen')->store('productos', 'public');
                $data['imagen'] = $path;
            }
            
            if ($request->has('imagen_url') && !empty($request->imagen_url)) {
                $data['imagen'] = $request->imagen_url;
            }

            $producto = Producto::create($data);

            // Sincronizar ingredientes (acepta 'id' o 'ingrediente_id')
            if ($request->has('ingredientes')) {
                $ingredientesData = [];
                foreach ($request->ingredientes as $item) {
                    $ingredienteId = $item['id'] ?? $item['ingrediente_id'] ?? null;
                    if ($ingredienteId) {
                        $cantidad = $item['cantidad'] ?? 1;
                        $ingredientesData[$ingredienteId] = ['cantidad' => $cantidad];
                    }
                }
                if (!empty($ingredientesData)) {
                    $producto->ingredientes()->sync($ingredientesData);
                }
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'CREAR_PRODUCTO',
                    'productos',
                    $producto->id,
                    "Producto creado: {$producto->nombre} - Precio: \${$producto->precio} - Stock: {$producto->stock}"
                );
            }

            $producto->load(['categoria', 'ingredientes']);

            return response()->json([
                'success' => true,
                'message' => 'Producto creado correctamente',
                'data' => $this->formatProductoResponse($producto)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en ProductoController@store: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear producto',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Actualizar un producto
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar productos'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'nombre' => 'sometimes|string|max:150',
                'descripcion' => 'nullable|string|max:1000',
                'precio' => 'sometimes|numeric|min:0|max:999999.99',
                'categoria_id' => 'nullable|exists:categorias,id',
                'stock' => 'sometimes|integer|min:0',
                'stock_minimo' => 'sometimes|integer|min:0',
                'activo' => 'sometimes|boolean',
                'imagen' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                'imagen_url' => 'nullable|url|max:500',
                'eliminar_imagen' => 'nullable|boolean',
                'ingredientes' => 'nullable|array'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->has('nombre') && $request->nombre != $producto->nombre) {
                $existente = Producto::where('restaurante_id', $restauranteActivo->id)
                    ->where('nombre', $request->nombre)
                    ->where('id', '!=', $producto->id)
                    ->first();

                if ($existente) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Ya existe otro producto con este nombre en el restaurante'
                    ], 409);
                }
            }

            DB::beginTransaction();

            $data = $request->except(['imagen', 'imagen_url', 'eliminar_imagen', 'ingredientes']);

            if ($request->eliminar_imagen && $producto->imagen) {
                if (!filter_var($producto->imagen, FILTER_VALIDATE_URL)) {
                    Storage::disk('public')->delete($producto->imagen);
                }
                $data['imagen'] = null;
            }

            if ($request->hasFile('imagen')) {
                if ($producto->imagen && !filter_var($producto->imagen, FILTER_VALIDATE_URL)) {
                    Storage::disk('public')->delete($producto->imagen);
                }
                $path = $request->file('imagen')->store('productos', 'public');
                $data['imagen'] = $path;
            }
            
            if ($request->has('imagen_url') && !empty($request->imagen_url)) {
                if ($producto->imagen && !filter_var($producto->imagen, FILTER_VALIDATE_URL)) {
                    Storage::disk('public')->delete($producto->imagen);
                }
                $data['imagen'] = $request->imagen_url;
            }

            $producto->update($data);

            // Sincronizar ingredientes (acepta 'id' o 'ingrediente_id')
            if ($request->has('ingredientes')) {
                $ingredientesData = [];
                foreach ($request->ingredientes as $item) {
                    $ingredienteId = $item['id'] ?? $item['ingrediente_id'] ?? null;
                    if ($ingredienteId) {
                        $cantidad = $item['cantidad'] ?? 1;
                        $ingredientesData[$ingredienteId] = ['cantidad' => $cantidad];
                    }
                }
                $producto->ingredientes()->sync($ingredientesData);
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_PRODUCTO',
                    'productos',
                    $producto->id,
                    "Producto actualizado: {$producto->nombre}"
                );
            }

            $producto = Producto::with(['categoria', 'ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'message' => 'Producto actualizado correctamente',
                'data' => $this->formatProductoResponse($producto)
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en ProductoController@update: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar producto',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Eliminar un producto (soft delete)
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('ELIMINAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar productos'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $ventasAsociadas = $producto->ordenDetalles()->count();
            if ($ventasAsociadas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede eliminar el producto porque tiene {$ventasAsociadas} venta(s) asociada(s)",
                    'sugerencia' => 'Puedes desactivarlo en lugar de eliminarlo'
                ], 409);
            }

            $nombreProducto = $producto->nombre;
            
            if ($producto->imagen && !filter_var($producto->imagen, FILTER_VALIDATE_URL)) {
                Storage::disk('public')->delete($producto->imagen);
            }
            
            $producto->delete();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_PRODUCTO',
                    'productos',
                    $id,
                    "Producto eliminado: {$nombreProducto}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Producto eliminado correctamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error en ProductoController@destroy: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar producto',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Activar/Desactivar un producto
     */
    public function toggleActive(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $producto->activo = !$producto->activo;
            $producto->save();

            $estado = $producto->activo ? 'activado' : 'desactivado';

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'TOGGLE_PRODUCTO',
                    'productos',
                    $producto->id,
                    "Producto {$estado}: {$producto->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => "Producto {$estado} correctamente",
                'data' => [
                    'id' => $producto->id,
                    'activo' => (bool) $producto->activo
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error en ProductoController@toggleActive: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al cambiar estado',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener productos para select (comboBox)
     */
    public function selectList(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $productos = Producto::with('categoria')
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'precio', 'stock', 'stock_minimo', 'categoria_id']);

            return response()->json([
                'success' => true,
                'data' => $productos->map(function($producto) {
                    $label = $producto->nombre . ' - $' . number_format($producto->precio, 2);
                    
                    if ($producto->stock <= $producto->stock_minimo && $producto->stock > 0) {
                        $label .= ' (Stock: ' . $producto->stock . ')';
                    } elseif ($producto->stock <= 0) {
                        $label .= ' (Agotado)';
                    }
                    
                    if ($producto->categoria) {
                        $label = '[' . $producto->categoria->nombre . '] ' . $label;
                    }
                    
                    return [
                        'value' => $producto->id,
                        'label' => $label,
                        'precio' => (float) $producto->precio,
                        'stock' => (int) $producto->stock,
                        'agotado' => $producto->stock <= 0,
                        'categoria_id' => $producto->categoria_id,
                        'categoria_nombre' => $producto->categoria ? $producto->categoria->nombre : null
                    ];
                })
            ]);

        } catch (\Exception $e) {
            Log::error('Error en ProductoController@selectList: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener productos',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener productos con bajo stock
     */
    public function bajoStock(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $productos = Producto::with('categoria')
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->orderByRaw('stock - stock_minimo ASC')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $productos->map(function($producto) {
                    return [
                        'id' => $producto->id,
                        'nombre' => $producto->nombre,
                        'categoria' => $producto->categoria ? $producto->categoria->nombre : 'Sin categoría',
                        'stock' => (int) $producto->stock,
                        'stock_minimo' => (int) $producto->stock_minimo,
                        'diferencia' => $producto->stock_minimo - $producto->stock,
                        'precio' => (float) $producto->precio,
                        'precio_formateado' => '$' . number_format($producto->precio, 2),
                        'imagen_url' => $producto->imagen_url
                    ];
                })
            ]);

        } catch (\Exception $e) {
            Log::error('Error en ProductoController@bajoStock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener productos con bajo stock',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Ajustar stock de un producto
     */
    public function ajustarStock(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $validator = Validator::make($request->all(), [
                'cantidad' => 'required|integer|min:1',
                'tipo' => 'required|in:sumar,restar,asignar',
                'motivo' => 'nullable|string|max:255'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $stockAnterior = $producto->stock;
            $mensaje = '';

            DB::beginTransaction();

            switch ($request->tipo) {
                case 'sumar':
                    $producto->increment('stock', $request->cantidad);
                    $mensaje = "Stock aumentado en {$request->cantidad} unidades";
                    break;
                case 'restar':
                    if ($producto->stock < $request->cantidad) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Stock insuficiente'
                        ], 400);
                    }
                    $producto->decrement('stock', $request->cantidad);
                    $mensaje = "Stock reducido en {$request->cantidad} unidades";
                    break;
                case 'asignar':
                    $producto->update(['stock' => $request->cantidad]);
                    $mensaje = "Stock asignado a {$request->cantidad} unidades";
                    break;
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'AJUSTAR_STOCK',
                    'productos',
                    $producto->id,
                    "Stock ajustado: {$stockAnterior} → {$producto->stock} - Motivo: {$request->motivo}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'stock_anterior' => $stockAnterior,
                    'stock_actual' => $producto->stock
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Producto no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en ProductoController@ajustarStock: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al ajustar stock',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener productos disponibles (con stock de ingredientes suficiente)
     */
    /**
 * Obtener productos disponibles (con stock de ingredientes suficiente)
 * ✅ No requiere middleware restaurante_activo — lee restaurante_id del query param
 * ✅ Incluye productos sin receta (usando stock directo)
 */
public function disponibles(Request $request)
{
    try {
        // ── Resolver restaurante ───────────────────────────────────────────
        // Intenta primero el binding del middleware; si no existe, usa query param
        try {
            $restaurante = app('restaurante_activo');
        } catch (\Exception $e) {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere restaurante_id',
                ], 422);
            }
            $restaurante = \App\Models\Restaurante::find($restauranteId);
            if (!$restaurante) {
                return response()->json([
                    'success' => false,
                    'message' => 'Restaurante no encontrado',
                ], 404);
            }
        }

        // ── Query ─────────────────────────────────────────────────────────
        $productos = Producto::with(['categoria', 'ingredientes'])
            ->where('restaurante_id', $restaurante->id)
            ->where('activo', true)
            ->get();

        $data = $productos->map(function ($producto) {
            
            // ── Producto SIN receta ───────────────────────────────────────
            if ($producto->ingredientes->isEmpty()) {
                // Solo mostrar si tiene stock disponible
                if ($producto->stock <= 0) {
                    return null;
                }
                
                return [
                    'id'              => $producto->id,
                    'nombre'          => $producto->nombre,
                    'descripcion'     => $producto->descripcion,
                    'precio'          => (float) $producto->precio,
                    'imagen_url'      => $producto->imagen_url,
                    'categoria'       => $producto->categoria ? [
                        'id'     => $producto->categoria->id,
                        'nombre' => $producto->categoria->nombre,
                        'color'  => $producto->categoria->color,
                        'icono'  => $producto->categoria->icono,
                        'orden'  => $producto->categoria->orden ?? 99,
                    ] : null,
                    'stock_disponible' => (int) $producto->stock,
                    'bajo_stock'      => $producto->stock <= $producto->stock_minimo && $producto->stock_minimo > 0,
                    'agotado'         => $producto->stock <= 0,
                    'sin_receta'      => true,
                ];
            }

            // ── Producto CON receta (calcular stock por ingredientes) ──────
            $stockCalculado = $producto->ingredientes->map(function ($ing) {
                if ($ing->pivot->cantidad <= 0) return PHP_INT_MAX;
                return (int) floor($ing->stock_actual / $ing->pivot->cantidad);
            })->min();

            // Si no hay suficiente stock de ingredientes, excluir producto
            if ($stockCalculado <= 0) {
                return null;
            }

            $bajoStock = $producto->ingredientes->some(function ($ing) {
                return $ing->stock_actual <= $ing->stock_minimo;
            });

            return [
                'id'              => $producto->id,
                'nombre'          => $producto->nombre,
                'descripcion'     => $producto->descripcion,
                'precio'          => (float) $producto->precio,
                'imagen_url'      => $producto->imagen_url,
                'categoria'       => $producto->categoria ? [
                    'id'     => $producto->categoria->id,
                    'nombre' => $producto->categoria->nombre,
                    'color'  => $producto->categoria->color,
                    'icono'  => $producto->categoria->icono,
                    'orden'  => $producto->categoria->orden ?? 99,
                ] : null,
                'stock_disponible' => $stockCalculado,
                'bajo_stock'      => $bajoStock,
                'agotado'         => false,
                'sin_receta'      => false,
            ];
        })->filter()->values();

        // Ordenar por categoría y orden
        $data = $data->sortBy([
            ['categoria.orden', 'asc'],
            ['categoria.nombre', 'asc'],
            ['nombre', 'asc'],
        ])->values();

        return response()->json([
            'success' => true,
            'data'    => $data,
            'restaurante' => [
                'id' => $restaurante->id,
                'nombre' => $restaurante->nombre
            ]
        ]);

    } catch (\Exception $e) {
        \Log::error('Error en ProductoController@disponibles: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener productos disponibles',
            'error'   => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    /**
     * Importar productos desde un array
     */
    public function import(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'productos' => 'required|array|min:1|max:100',
                'productos.*.nombre' => 'required|string|max:150',
                'productos.*.precio' => 'required|numeric|min:0',
                'productos.*.descripcion' => 'nullable|string',
                'productos.*.categoria_id' => 'nullable|exists:categorias,id',
                'productos.*.stock' => 'nullable|integer|min:0',
                'productos.*.stock_minimo' => 'nullable|integer|min:0',
                'sobrescribir' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Error de validación',
                    'errors' => $validator->errors()
                ], 422);
            }

            $restauranteActivo = app('restaurante_activo');
            
            $resultados = [
                'creados' => 0,
                'actualizados' => 0,
                'errores' => []
            ];

            DB::beginTransaction();

            foreach ($request->productos as $item) {
                try {
                    $producto = Producto::where('restaurante_id', $restauranteActivo->id)
                        ->where('nombre', $item['nombre'])
                        ->first();

                    if ($producto && $request->sobrescribir) {
                        $producto->update([
                            'precio' => $item['precio'],
                            'descripcion' => $item['descripcion'] ?? $producto->descripcion,
                            'categoria_id' => $item['categoria_id'] ?? $producto->categoria_id,
                            'stock' => $item['stock'] ?? $producto->stock,
                            'stock_minimo' => $item['stock_minimo'] ?? $producto->stock_minimo,
                            'activo' => true
                        ]);
                        $resultados['actualizados']++;
                    } elseif (!$producto) {
                        Producto::create([
                            'restaurante_id' => $restauranteActivo->id,
                            'categoria_id' => $item['categoria_id'] ?? null,
                            'nombre' => $item['nombre'],
                            'descripcion' => $item['descripcion'] ?? null,
                            'precio' => $item['precio'],
                            'stock' => $item['stock'] ?? 0,
                            'stock_minimo' => $item['stock_minimo'] ?? 5,
                            'activo' => true
                        ]);
                        $resultados['creados']++;
                    }
                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'producto' => $item['nombre'],
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            $mensaje = "Importación completada: {$resultados['creados']} creados, {$resultados['actualizados']} actualizados";
            if (count($resultados['errores']) > 0) {
                $mensaje .= ", " . count($resultados['errores']) . " errores";
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => $resultados
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error en ProductoController@import: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error en importación',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Obtener imagen de un producto
     */
    public function imagen($id)
    {
        try {
            $producto = Producto::findOrFail($id);
            
            if (!$producto->imagen) {
                return response()->json(['message' => 'No image found'], 404);
            }
            
            if (filter_var($producto->imagen, FILTER_VALIDATE_URL)) {
                return redirect($producto->imagen);
            }
            
            $path = Storage::disk('public')->path($producto->imagen);
            
            if (!file_exists($path)) {
                return response()->json(['message' => 'Image not found'], 404);
            }
            
            return response()->file($path);
            
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Product not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error en ProductoController@imagen: ' . $e->getMessage());
            return response()->json(['message' => 'Error loading image'], 500);
        }
    }

    /**
     * Formatear respuesta de producto con ingredientes
     */
    private function formatProductoResponse($producto)
    {
        $bajoStock = $producto->stock <= $producto->stock_minimo;
        
        return [
            'id' => $producto->id,
            'nombre' => $producto->nombre,
            'descripcion' => $producto->descripcion,
            
            'categoria' => $producto->categoria ? [
                'id' => $producto->categoria->id,
                'nombre' => $producto->categoria->nombre,
                'color' => $producto->categoria->color,
                'icono' => $producto->categoria->icono
            ] : null,
            'categoria_id' => $producto->categoria_id,

            'ingredientes' => $producto->ingredientes->map(function($ing) {
                return [
                    'id' => $ing->id,
                    'nombre' => $ing->nombre,
                    'unidad' => $ing->unidad,
                    'stock_actual' => (float) $ing->stock_actual,
                    'stock_minimo' => (float) $ing->stock_minimo,
                    'costo_unitario' => (float) $ing->costo_unitario,
                    'cantidad_necesaria' => (float) ($ing->pivot->cantidad ?? 0)
                ];
            })->toArray(),
            
            'tiene_ingredientes' => $producto->ingredientes->isNotEmpty(),
            'puede_prepararse' => $this->puedePrepararse($producto),
            'unidades_posibles' => $this->calcularUnidadesPosibles($producto),
            
            'imagen' => $producto->imagen,
            'imagen_url' => $producto->imagen_url,

            'precio' => (float) $producto->precio,
            'precio_formateado' => '$' . number_format($producto->precio, 2),
            
            'stock' => (int) $producto->stock,
            'stock_minimo' => (int) $producto->stock_minimo,
            'bajo_stock' => $bajoStock,
            'agotado' => $producto->stock <= 0,
            'estado_stock' => $this->getEstadoStock($producto->stock, $producto->stock_minimo),
            
            'activo' => (bool) $producto->activo,
            'created_at' => $producto->created_at,
            'created_at_formateado' => $producto->created_at ? $producto->created_at->format('d/m/Y H:i') : null,
            'updated_at' => $producto->updated_at,
            'updated_at_formateado' => $producto->updated_at ? $producto->updated_at->format('d/m/Y H:i') : null,
        ];
    }

    /**
     * Verificar si un producto se puede preparar con los ingredientes disponibles
     */
    private function puedePrepararse($producto)
    {
        if ($producto->ingredientes->isEmpty()) {
            return false;
        }
        
        return $producto->ingredientes->every(function($ing) {
            $cantidadNecesaria = $ing->pivot->cantidad ?? 0;
            return $cantidadNecesaria > 0 && $ing->stock_actual >= $cantidadNecesaria;
        });
    }

    /**
     * Calcular cuántas unidades del producto se pueden preparar
     */
    private function calcularUnidadesPosibles($producto)
    {
        if ($producto->ingredientes->isEmpty()) {
            return 0;
        }
        
        return $producto->ingredientes->map(function($ing) {
            $cantidadNecesaria = $ing->pivot->cantidad ?? 0;
            if ($cantidadNecesaria <= 0) return PHP_INT_MAX;
            return floor($ing->stock_actual / $cantidadNecesaria);
        })->min();
    }

    /**
     * Métodos auxiliares
     */
    private function getEstadoStock($stock, $stockMinimo)
    {
        if ($stock <= 0) {
            return [
                'texto' => 'Sin stock',
                'color' => 'red',
                'icono' => '❌',
                'clase' => 'bg-red-100 text-red-800'
            ];
        }
        if ($stock <= $stockMinimo) {
            return [
                'texto' => 'Bajo stock',
                'color' => 'yellow',
                'icono' => '⚠️',
                'clase' => 'bg-yellow-100 text-yellow-800'
            ];
        }
        return [
            'texto' => 'Normal',
            'color' => 'green',
            'icono' => '✅',
            'clase' => 'bg-green-100 text-green-800'
        ];
    }

    private function getProductosPorCategoria($restauranteId)
    {
        try {
            $categorias = Categoria::where('restaurante_id', $restauranteId)->get();
            $result = [];
            
            foreach ($categorias as $categoria) {
                $result[$categoria->nombre] = Producto::where('restaurante_id', $restauranteId)
                    ->where('categoria_id', $categoria->id)
                    ->count();
            }
            
            $sinCategoria = Producto::where('restaurante_id', $restauranteId)
                ->whereNull('categoria_id')
                ->count();
            
            if ($sinCategoria > 0) {
                $result['Sin categoría'] = $sinCategoria;
            }
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('Error en getProductosPorCategoria: ' . $e->getMessage());
            return [];
        }
    }
    /**
 * ⭐ NUEVO: Listar productos públicamente (sin autenticación)
 * Para clientes que navegan por el catálogo
 */
public function indexPublic(Request $request)
{
    try {
        // Obtener restaurante de query param
        $restauranteId = $request->get('restaurante_id');
        if (!$restauranteId) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere restaurante_id'
            ], 422);
        }

        $restaurante = \App\Models\Restaurante::find($restauranteId);
        if (!$restaurante) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no encontrado'
            ], 404);
        }

        // Query base: solo productos activos
        $query = Producto::with(['categoria'])
            ->where('restaurante_id', $restaurante->id)
            ->where('activo', true);

        // Filtros básicos para clientes
        if ($request->has('categoria_id') && !empty($request->categoria_id)) {
            $query->where('categoria_id', $request->categoria_id);
        }

        if ($request->has('buscar') && !empty($request->buscar)) {
            $buscar = $request->buscar;
            $query->where(function($q) use ($buscar) {
                $q->where('nombre', 'like', "%{$buscar}%")
                  ->orWhere('descripcion', 'like', "%{$buscar}%");
            });
        }

        // Ordenamiento
        $orderBy = $request->get('order_by', 'nombre');
        $orderDir = $request->get('order_dir', 'asc');
        $allowedFields = ['nombre', 'precio', 'created_at'];
        if (in_array($orderBy, $allowedFields)) {
            $query->orderBy($orderBy, $orderDir);
        } else {
            $query->orderBy('nombre', 'asc');
        }

        $perPage = min($request->get('per_page', 20), 50);
        $productos = $query->paginate($perPage);

        // Formatear respuesta para cliente (sin datos sensibles)
        $productosData = $productos->map(function($producto) {
            return [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'descripcion' => $producto->descripcion,
                'precio' => (float) $producto->precio,
                'precio_formateado' => '$' . number_format($producto->precio, 2),
                'imagen_url' => $producto->imagen_url,
                'categoria' => $producto->categoria ? [
                    'id' => $producto->categoria->id,
                    'nombre' => $producto->categoria->nombre,
                    'color' => $producto->categoria->color,
                    'icono' => $producto->categoria->icono
                ] : null,
                'disponible' => $this->checkDisponibilidadPublica($producto),
                'tiene_oferta' => $this->checkOfertaActiva($producto)
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $productosData,
            'pagination' => [
                'current_page' => $productos->currentPage(),
                'per_page' => $productos->perPage(),
                'total' => $productos->total(),
                'last_page' => $productos->lastPage()
            ],
            'restaurante' => [
                'id' => $restaurante->id,
                'nombre' => $restaurante->nombre
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Error en ProductoController@indexPublic: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener productos'
        ], 500);
    }
}

/**
 * ⭐ NUEVO: Mostrar un producto específico (público)
 */
public function showPublic($id, Request $request)
{
    try {
        $restauranteId = $request->get('restaurante_id');
        
        $query = Producto::with(['categoria'])
            ->where('id', $id)
            ->where('activo', true);
        
        if ($restauranteId) {
            $query->where('restaurante_id', $restauranteId);
        }
        
        $producto = $query->firstOrFail();

        // Obtener ofertas activas para este producto
        $ofertas = \App\Models\Oferta::where('producto_id', $producto->id)
            ->where('activa', true)
            ->where('fecha_inicio', '<=', now())
            ->where('fecha_fin', '>=', now())
            ->get();

        // Calcular precio con descuento si aplica
        $precioOriginal = (float) $producto->precio;
        $precioFinal = $precioOriginal;
        $descuentoAplicado = null;

        if ($ofertas->isNotEmpty()) {
            $mejorOferta = $ofertas->sortByDesc('descuento_porcentaje')->first();
            $descuentoAplicado = [
                'porcentaje' => (float) $mejorOferta->descuento_porcentaje,
                'precio_con_descuento' => (float) ($precioOriginal * (1 - $mejorOferta->descuento_porcentaje / 100)),
                'titulo' => $mejorOferta->titulo
            ];
            $precioFinal = $descuentoAplicado['precio_con_descuento'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'descripcion' => $producto->descripcion,
                'precio_original' => $precioOriginal,
                'precio_original_formateado' => '$' . number_format($precioOriginal, 2),
                'precio_final' => $precioFinal,
                'precio_final_formateado' => '$' . number_format($precioFinal, 2),
                'descuento' => $descuentoAplicado,
                'imagen_url' => $producto->imagen_url,
                'categoria' => $producto->categoria ? [
                    'id' => $producto->categoria->id,
                    'nombre' => $producto->categoria->nombre
                ] : null,
                'disponible' => $this->checkDisponibilidadPublica($producto),
                'created_at' => $producto->created_at?->format('d/m/Y')
            ]
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Producto no encontrado'
        ], 404);
    } catch (\Exception $e) {
        Log::error('Error en ProductoController@showPublic: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener producto'
        ], 500);
    }
}

/**
 * ⭐ NUEVO: Productos por categoría (público)
 */
public function porCategoria($categoriaId, Request $request)
{
    try {
        $restauranteId = $request->get('restaurante_id');
        
        $query = Producto::with(['categoria'])
            ->where('categoria_id', $categoriaId)
            ->where('activo', true);
        
        if ($restauranteId) {
            $query->where('restaurante_id', $restauranteId);
        }
        
        $productos = $query->get();
        
        // Verificar si la categoría existe
        $categoria = \App\Models\Categoria::find($categoriaId);
        
        return response()->json([
            'success' => true,
            'data' => [
                'categoria' => $categoria ? [
                    'id' => $categoria->id,
                    'nombre' => $categoria->nombre,
                    'descripcion' => $categoria->descripcion
                ] : null,
                'productos' => $productos->map(function($producto) {
                    return [
                        'id' => $producto->id,
                        'nombre' => $producto->nombre,
                        'precio' => (float) $producto->precio,
                        'precio_formateado' => '$' . number_format($producto->precio, 2),
                        'imagen_url' => $producto->imagen_url,
                        'disponible' => $this->checkDisponibilidadPublica($producto)
                    ];
                })
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Error en ProductoController@porCategoria: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener productos por categoría'
        ], 500);
    }
}

/**
 * ⭐ NUEVO: Productos disponibles (versión pública simple)
 * Similar a disponibles() pero sin necesidad de auth
 */
public function disponiblesPublic(Request $request)
{
    try {
        $restauranteId = $request->get('restaurante_id');
        if (!$restauranteId) {
            return response()->json([
                'success' => false,
                'message' => 'Se requiere restaurante_id'
            ], 422);
        }

        $restaurante = \App\Models\Restaurante::find($restauranteId);
        if (!$restaurante) {
            return response()->json([
                'success' => false,
                'message' => 'Restaurante no encontrado'
            ], 404);
        }

        $productos = Producto::with(['categoria', 'ingredientes'])
            ->where('restaurante_id', $restaurante->id)
            ->where('activo', true)
            ->get();

        $data = $productos->map(function ($producto) {
            
            // Producto SIN receta
            if ($producto->ingredientes->isEmpty()) {
                if ($producto->stock <= 0) {
                    return null;
                }
                
                return [
                    'id' => $producto->id,
                    'nombre' => $producto->nombre,
                    'descripcion' => $producto->descripcion,
                    'precio' => (float) $producto->precio,
                    'precio_formateado' => '$' . number_format($producto->precio, 2),
                    'imagen_url' => $producto->imagen_url,
                    'categoria' => $producto->categoria ? [
                        'id' => $producto->categoria->id,
                        'nombre' => $producto->categoria->nombre,
                    ] : null,
                    'disponible' => true,
                    'stock_restante' => (int) $producto->stock
                ];
            }

            // Producto CON receta
            $stockCalculado = $producto->ingredientes->map(function ($ing) {
                if ($ing->pivot->cantidad <= 0) return PHP_INT_MAX;
                return (int) floor($ing->stock_actual / $ing->pivot->cantidad);
            })->min();

            if ($stockCalculado <= 0) {
                return null;
            }

            return [
                'id' => $producto->id,
                'nombre' => $producto->nombre,
                'descripcion' => $producto->descripcion,
                'precio' => (float) $producto->precio,
                'precio_formateado' => '$' . number_format($producto->precio, 2),
                'imagen_url' => $producto->imagen_url,
                'categoria' => $producto->categoria ? [
                    'id' => $producto->categoria->id,
                    'nombre' => $producto->categoria->nombre,
                ] : null,
                'disponible' => true,
                'stock_restante' => $stockCalculado
            ];
        })->filter()->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'restaurante' => [
                'id' => $restaurante->id,
                'nombre' => $restaurante->nombre
            ]
        ]);

    } catch (\Exception $e) {
        Log::error('Error en ProductoController@disponiblesPublic: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Error al obtener productos disponibles'
        ], 500);
    }
}

// ========== MÉTODOS AUXILIARES PRIVADOS ==========

/**
 * Verificar disponibilidad de un producto (para cliente público)
 */
private function checkDisponibilidadPublica($producto)
{
    if (!$producto->activo) {
        return false;
    }
    
    // Sin receta: verificar stock directo
    if ($producto->ingredientes->isEmpty()) {
        return $producto->stock > 0;
    }
    
    // Con receta: verificar stock de ingredientes
    return $producto->ingredientes->every(function($ing) {
        $cantidadNecesaria = $ing->pivot->cantidad ?? 0;
        return $cantidadNecesaria > 0 && $ing->stock_actual >= $cantidadNecesaria;
    });
}

/**
 * Verificar si el producto tiene oferta activa
 */
private function checkOfertaActiva($producto)
{
    return \App\Models\Oferta::where('producto_id', $producto->id)
        ->where('activa', true)
        ->where('fecha_inicio', '<=', now())
        ->where('fecha_fin', '>=', now())
        ->exists();
}
}