<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CATEGORIAS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver categorías'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $perPage = $request->get('per_page', 15);
            $query = Categoria::where('restaurante_id', $restauranteActivo->id);

            if ($request->has('buscar')) {
                $buscar = $request->buscar;
                $query->where('nombre', 'like', "%{$buscar}%");
            }

            if ($request->has('activo')) {
                $query->where('activo', $request->activo);
            }

            $categorias = $query->orderBy('orden')->orderBy('nombre')->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $categorias->items(),
                'pagination' => [
                    'current_page' => $categorias->currentPage(),
                    'per_page' => $categorias->perPage(),
                    'total' => $categorias->total(),
                    'last_page' => $categorias->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener categorías',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_CATEGORIAS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para crear categorías'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'nombre' => 'required|string|max:255',
                'descripcion' => 'nullable|string',
                'color' => 'nullable|string|max:20',
                'icono' => 'nullable|string|max:50',
                'orden' => 'nullable|integer'
            ]);

            DB::beginTransaction();

            $categoria = Categoria::create([
                'restaurante_id' => $restauranteActivo->id,
                'nombre' => $request->nombre,
                'descripcion' => $request->descripcion,
                'color' => $request->color ?? '#6B7280',
                'icono' => $request->icono,
                'orden' => $request->orden ?? 0,
                'activo' => true
            ]);

            DB::commit();

            // Log de acción
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'CREAR_CATEGORIA',
                    'categorias',
                    $categoria->id,
                    "Categoría creada: {$categoria->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Categoría creada correctamente',
                'data' => $categoria
            ], 201);

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
                'message' => 'Error al crear categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CATEGORIA')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $categoria = Categoria::where('restaurante_id', $restauranteActivo->id)
                ->with('productos')
                ->findOrFail($id);

            // POLICY: Verificar que puede ver esta categoría
            if (Gate::denies('view', $categoria)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta categoría'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $categoria
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_CATEGORIAS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar categorías'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $categoria = Categoria::where('restaurante_id', $restauranteActivo->id)
                ->findOrFail($id);

            // POLICY: Verificar que puede editar esta categoría
            if (Gate::denies('update', $categoria)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta categoría'
                ], 403);
            }

            $request->validate([
                'nombre' => 'sometimes|string|max:255',
                'descripcion' => 'nullable|string',
                'color' => 'nullable|string|max:20',
                'icono' => 'nullable|string|max:50',
                'orden' => 'nullable|integer',
                'activo' => 'sometimes|boolean'
            ]);

            DB::beginTransaction();

            $categoria->update($request->all());

            DB::commit();

            // Log de acción
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_CATEGORIA',
                    'categorias',
                    $categoria->id,
                    "Categoría actualizada: {$categoria->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Categoría actualizada correctamente',
                'data' => $categoria
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada'
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
                'message' => 'Error al actualizar categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('ELIMINAR_CATEGORIAS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar categorías'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $categoria = Categoria::where('restaurante_id', $restauranteActivo->id)
                ->findOrFail($id);

            // POLICY: Verificar que puede eliminar esta categoría
            if (Gate::denies('delete', $categoria)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a esta categoría'
                ], 403);
            }

            // Verificar si tiene productos asociados
            if ($categoria->productos()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar la categoría porque tiene productos asociados'
                ], 409);
            }

            DB::beginTransaction();

            $categoria->delete();

            DB::commit();

            // Log de acción
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_CATEGORIA',
                    'categorias',
                    $id,
                    "Categoría eliminada: {$categoria->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Categoría eliminada correctamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Categoría no encontrada'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar categoría',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function selectList(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CATEGORIAS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $categorias = Categoria::where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'color']);

            return response()->json([
                'success' => true,
                'data' => $categorias->map(function($cat) {
                    return [
                        'value' => $cat->id,
                        'label' => $cat->nombre,
                        'color' => $cat->color
                    ];
                })
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener lista',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}