<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingrediente;
use App\Models\IngredienteMovimiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngredienteController extends Controller
{
    /**
     * Listar ingredientes con filtros y estadísticas
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para ver ingredientes'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $query = Ingrediente::with('productos:id,nombre')
                ->where('restaurante_id', $restaurante->id);

            // Filtros
            if ($request->filled('buscar')) {
                $b = $request->buscar;
                $query->where(fn($q) => $q->where('nombre', 'like', "%{$b}%")
                    ->orWhere('proveedor', 'like', "%{$b}%"));
            }

            if ($request->filled('bajo_stock')) {
                $query->whereColumn('stock_actual', '<=', 'stock_minimo');
            }

            if ($request->filled('activo')) {
                $query->where('activo', $request->boolean('activo'));
            }

            $ingredientes = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data' => $ingredientes->map(fn($i) => $this->transform($i)),
                'stats' => [
                    'total' => $ingredientes->count(),
                    'bajo_stock' => $ingredientes->filter(fn($i) => $i->bajo_stock)->count(),
                    'sin_stock' => $ingredientes->filter(fn($i) => $i->stock_actual <= 0)->count(),
                    'costo_total' => round($ingredientes->sum(fn($i) => $i->costo_total_stock), 2),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ingredientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo ingrediente
     */
    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'unidad' => 'required|string|max:30',
            'costo_unitario' => 'required|numeric|min:0',
            'stock_actual' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'proveedor' => 'nullable|string|max:150',
        ]);

        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para crear ingredientes'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            
            $ingrediente = Ingrediente::create([
                'restaurante_id' => $restaurante->id,
                'nombre' => $request->nombre,
                'unidad' => $request->unidad,
                'costo_unitario' => $request->costo_unitario,
                'stock_actual' => $request->stock_actual ?? 0,
                'stock_minimo' => $request->stock_minimo ?? 0,
                'proveedor' => $request->proveedor,
                'activo' => true,
            ]);

            // Registrar movimiento inicial
            if ($ingrediente->stock_actual > 0) {
                IngredienteMovimiento::create([
                    'ingrediente_id' => $ingrediente->id,
                    'user_id' => $user->id,
                    'tipo' => 'entrada',
                    'cantidad_anterior' => 0,
                    'cantidad_movimiento' => $ingrediente->stock_actual,
                    'cantidad_nueva' => $ingrediente->stock_actual,
                    'motivo' => 'Stock inicial al crear ingrediente',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Ingrediente creado correctamente',
                'data' => $this->transform($ingrediente)
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear ingrediente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un ingrediente específico
     */
    public function show($id)
    {
        try {
            $user = request()->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $ingrediente = Ingrediente::with('productos:id,nombre')
                ->where('restaurante_id', $restaurante->id)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->transform($ingrediente)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ingrediente no encontrado'
            ], 404);
        }
    }

    /**
     * Actualizar un ingrediente
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'unidad' => 'sometimes|string|max:30',
            'costo_unitario' => 'sometimes|numeric|min:0',
            'stock_minimo' => 'sometimes|numeric|min:0',
            'proveedor' => 'nullable|string|max:150',
            'activo' => 'sometimes|boolean',
        ]);

        try {
            $user = request()->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para editar ingredientes'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $ingrediente = Ingrediente::where('restaurante_id', $restaurante->id)->findOrFail($id);
            
            $ingrediente->update($request->only([
                'nombre', 'unidad', 'costo_unitario', 'stock_minimo', 'proveedor', 'activo'
            ]));

            return response()->json([
                'success' => true,
                'message' => 'Ingrediente actualizado',
                'data' => $this->transform($ingrediente)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar ingrediente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajustar stock de un ingrediente (entrada/salida/ajuste)
     */
    public function ajustarStock(Request $request, $id)
    {
        $request->validate([
            'tipo' => 'required|in:entrada,salida,ajuste',
            'cantidad' => 'required|numeric|min:0',
            'motivo' => 'nullable|string|max:200',
        ]);

        try {
            $user = request()->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para ajustar stock'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $ingrediente = Ingrediente::where('restaurante_id', $restaurante->id)->findOrFail($id);

            $anterior = $ingrediente->stock_actual;

            switch ($request->tipo) {
                case 'ajuste':
                    $ingrediente->stock_actual = $request->cantidad;
                    break;
                case 'entrada':
                    $ingrediente->stock_actual += abs($request->cantidad);
                    break;
                case 'salida':
                    $ingrediente->stock_actual = max(0, $ingrediente->stock_actual - abs($request->cantidad));
                    break;
            }
            $ingrediente->save();

            // Registrar movimiento
            IngredienteMovimiento::create([
                'ingrediente_id' => $ingrediente->id,
                'user_id' => $user->id,
                'tipo' => $request->tipo,
                'cantidad_anterior' => $anterior,
                'cantidad_movimiento' => abs($request->cantidad),
                'cantidad_nueva' => $ingrediente->stock_actual,
                'motivo' => $request->motivo,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stock actualizado',
                'data' => $this->transform($ingrediente)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ajustar stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Historial de movimientos de un ingrediente
     */
    public function historial($id)
    {
        try {
            $user = request()->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $ingrediente = Ingrediente::where('restaurante_id', $restaurante->id)->findOrFail($id);
            
            $movimientos = IngredienteMovimiento::with('user:id,name')
                ->where('ingrediente_id', $ingrediente->id)
                ->orderByDesc('created_at')
                ->paginate(50);

            return response()->json([
                'success' => true,
                'data' => [
                    'ingrediente' => $this->transform($ingrediente),
                    'movimientos' => $movimientos->items(),
                    'pagination' => [
                        'current_page' => $movimientos->currentPage(),
                        'per_page' => $movimientos->perPage(),
                        'total' => $movimientos->total(),
                        'last_page' => $movimientos->lastPage(),
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar ingredientes a un producto
     */
    public function syncProducto(Request $request, $productoId)
    {
        $request->validate([
            'ingredientes' => 'required|array',
            'ingredientes.*.id' => 'required|exists:ingredientes,id',
            'ingredientes.*.cantidad' => 'required|numeric|min:0.001',
        ]);

        try {
            $user = request()->user();
            
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso'
                ], 403);
            }

            $sync = [];
            foreach ($request->ingredientes as $item) {
                $sync[$item['id']] = ['cantidad' => $item['cantidad']];
            }
            
            \App\Models\Producto::findOrFail($productoId)->ingredientes()->sync($sync);

            return response()->json([
                'success' => true,
                'message' => 'Ingredientes del producto actualizados'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar ingredientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener ingredientes de un producto
     */
    public function deProducto($productoId)
    {
        try {
            $user = request()->user();
            
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso'
                ], 403);
            }

            $producto = \App\Models\Producto::with('ingredientes')->findOrFail($productoId);
            
            return response()->json([
                'success' => true,
                'data' => $producto->ingredientes->map(fn($i) => [
                    ...$this->transform($i),
                    'cantidad_receta' => (float) $i->pivot->cantidad,
                    'costo_receta' => round($i->pivot->cantidad * $i->costo_unitario, 4),
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener ingredientes del producto',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un ingrediente (soft delete)
     */
    public function destroy($id)
    {
        try {
            $user = request()->user();
            
            if (!$user->hasPermission('ELIMINAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para eliminar ingredientes'
                ], 403);
            }

            $restaurante = app('restaurante_activo');
            $ingrediente = Ingrediente::where('restaurante_id', $restaurante->id)->findOrFail($id);
            
            // Verificar si está siendo usado en algún producto
            if ($ingrediente->productos()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el ingrediente porque está siendo usado en productos'
                ], 409);
            }
            
            $ingrediente->delete();

            return response()->json([
                'success' => true,
                'message' => 'Ingrediente eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar ingrediente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Transformar datos del ingrediente para la respuesta
     */
    private function transform(Ingrediente $i): array
    {
        return [
            'id' => $i->id,
            'nombre' => $i->nombre,
            'unidad' => $i->unidad,
            'costo_unitario' => (float) $i->costo_unitario,
            'costo_formateado' => '$' . number_format($i->costo_unitario, 4),
            'stock_actual' => (float) $i->stock_actual,
            'stock_minimo' => (float) $i->stock_minimo,
            'bajo_stock' => $i->bajo_stock,
            'sin_stock' => $i->stock_actual <= 0,
            'costo_total_stock' => $i->costo_total_stock,
            'proveedor' => $i->proveedor,
            'activo' => $i->activo,
            'productos_count' => $i->productos?->count() ?? 0,
            'created_at' => $i->created_at,
            'updated_at' => $i->updated_at,
        ];
    }
}