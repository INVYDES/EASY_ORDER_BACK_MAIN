<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InsumoPreparado;
use App\Models\InsumoPreparadoMovimiento;
use Illuminate\Http\Request;

class InsumosPreparadosController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');
            $query = InsumoPreparado::with('receta:id,nombre,unidad')
                ->where('restaurante_id', $restaurante->id);

            if ($request->filled('buscar')) {
                $b = $request->buscar;
                $query->where('nombre', 'like', "%{$b}%");
            }

            if ($request->filled('activo')) {
                $query->where('activo', $request->boolean('activo'));
            }

            $insumos = $query->orderBy('nombre')->get();

            return response()->json([
                'success' => true,
                'data' => $insumos->map(fn($i) => $this->transform($i)),
                'stats' => [
                    'total' => $insumos->count(),
                    'bajo_stock' => $insumos->filter(fn($i) => $i->bajo_stock)->count(),
                    'sin_stock' => $insumos->filter(fn($i) => $i->stock_actual <= 0)->count(),
                    'costo_total' => round($insumos->sum(fn($i) => $i->costo_total_stock), 2),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener insumos preparados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:100',
            'unidad' => 'required|string|max:30',
            'costo_unitario' => 'required|numeric|min:0',
            'stock_actual' => 'nullable|numeric|min:0',
            'stock_minimo' => 'nullable|numeric|min:0',
            'vida_util_dias' => 'nullable|integer|min:0',
            'receta' => 'nullable|array',
            'receta.*.ingrediente_id' => 'required_with:receta|exists:ingredientes,id',
            'receta.*.cantidad' => 'required_with:receta|numeric|min:0.001',
        ]);

        try {
            $user = $request->user();
            if (!$user->hasPermission('CREAR_PRODUCTOS')) {
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');

            $insumo = InsumoPreparado::create([
                'restaurante_id' => $restaurante->id,
                'nombre' => $request->nombre,
                'unidad' => $request->unidad,
                'costo_unitario' => $request->costo_unitario,
                'stock_actual' => $request->stock_actual ?? 0,
                'stock_minimo' => $request->stock_minimo ?? 0,
                'vida_util_dias' => $request->vida_util_dias,
                'activo' => true,
            ]);

            // Sincronizar receta
            if ($request->filled('receta')) {
                $sync = [];
                foreach ($request->receta as $item) {
                    $sync[$item['ingrediente_id']] = ['cantidad' => $item['cantidad']];
                }
                $insumo->receta()->sync($sync);
            }

            // Movimiento inicial
            if ($insumo->stock_actual > 0) {
                InsumoPreparadoMovimiento::create([
                    'insumo_preparado_id' => $insumo->id,
                    'user_id' => $user->id,
                    'tipo' => 'entrada',
                    'cantidad_anterior' => 0,
                    'cantidad_movimiento' => $insumo->stock_actual,
                    'cantidad_nueva' => $insumo->stock_actual,
                    'motivo' => 'Stock inicial al crear insumo preparado',
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Insumo preparado creado correctamente',
                'data' => $this->transform($insumo->load('receta:id,nombre,unidad'))
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear insumo preparado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = request()->user();
            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');
            $insumo = InsumoPreparado::with('receta:id,nombre,unidad')
                ->where('restaurante_id', $restaurante->id)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $this->transform($insumo)
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Insumo no encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'sometimes|string|max:100',
            'unidad' => 'sometimes|string|max:30',
            'costo_unitario' => 'sometimes|numeric|min:0',
            'stock_actual' => 'sometimes|numeric|min:0',
            'stock_minimo' => 'sometimes|numeric|min:0',
            'vida_util_dias' => 'nullable|integer|min:0',
            'activo' => 'sometimes|boolean',
            'receta' => 'nullable|array',
            'receta.*.ingrediente_id' => 'required_with:receta|exists:ingredientes,id',
            'receta.*.cantidad' => 'required_with:receta|numeric|min:0.001',
        ]);

        try {
            $user = request()->user();
            if (!$user->hasPermission('EDITAR_PRODUCTOS')) {
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');
            $insumo = InsumoPreparado::where('restaurante_id', $restaurante->id)->findOrFail($id);

            $insumo->update($request->only([
                'nombre', 'unidad', 'costo_unitario', 'stock_actual', 'stock_minimo', 'vida_util_dias', 'activo'
            ]));

            if ($request->has('receta')) {
                $sync = [];
                foreach ($request->receta as $item) {
                    $sync[$item['ingrediente_id']] = ['cantidad' => $item['cantidad']];
                }
                $insumo->receta()->sync($sync);
            }

            return response()->json([
                'success' => true,
                'message' => 'Insumo preparado actualizado',
                'data' => $this->transform($insumo->load('receta:id,nombre,unidad'))
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar insumo preparado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = request()->user();
            if (!$user->hasPermission('ELIMINAR_PRODUCTOS')) {
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');
            $insumo = InsumoPreparado::where('restaurante_id', $restaurante->id)->findOrFail($id);

            if ($insumo->receta()->count() > 0) {
                $insumo->receta()->detach();
            }

            $insumo->delete();

            return response()->json([
                'success' => true,
                'message' => 'Insumo preparado eliminado correctamente'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar insumo preparado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

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
                return response()->json(['success' => false, 'message' => 'Sin permiso'], 403);
            }

            $restaurante = app('restaurante_activo');
            $insumo = InsumoPreparado::where('restaurante_id', $restaurante->id)->findOrFail($id);

            $anterior = $insumo->stock_actual;

            switch ($request->tipo) {
                case 'ajuste':
                    $insumo->stock_actual = $request->cantidad;
                    break;
                case 'entrada':
                    $insumo->stock_actual += abs($request->cantidad);
                    break;
                case 'salida':
                    $insumo->stock_actual = max(0, $insumo->stock_actual - abs($request->cantidad));
            }
            $insumo->save();

            InsumoPreparadoMovimiento::create([
                'insumo_preparado_id' => $insumo->id,
                'user_id' => $user->id,
                'tipo' => $request->tipo,
                'cantidad_anterior' => $anterior,
                'cantidad_movimiento' => abs($request->cantidad),
                'cantidad_nueva' => $insumo->stock_actual,
                'motivo' => $request->motivo,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Stock actualizado',
                'data' => $this->transform($insumo)
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al ajustar stock',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    private function transform(InsumoPreparado $i): array
    {
        return [
            'id' => $i->id,
            'nombre' => $i->nombre,
            'unidad' => $i->unidad,
            'costo_unitario' => (float) $i->costo_unitario,
            'costo_formateado' => '$' . number_format($i->costo_unitario, 4),
            'stock_actual' => (float) $i->stock_actual,
            'stock_minimo' => (float) $i->stock_minimo,
            'vida_util_dias' => $i->vida_util_dias,
            'bajo_stock' => $i->bajo_stock,
            'sin_stock' => $i->stock_actual <= 0,
            'costo_total_stock' => $i->costo_total_stock,
            'activo' => $i->activo,
            'receta_count' => $i->receta?->count() ?? 0,
            'receta' => $i->receta?->map(fn($r) => [
                'id' => $r->id,
                'ingrediente_id' => $r->id,
                'nombre' => $r->nombre,
                'unidad' => $r->unidad,
                'cantidad' => (float) $r->pivot->cantidad,
            ]) ?? [],
            'created_at' => $i->created_at,
            'updated_at' => $i->updated_at,
        ];
    }
}
