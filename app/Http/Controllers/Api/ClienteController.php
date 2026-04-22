<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CLIENTES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver clientes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $perPage = $request->get('per_page', 15);
            $query = Cliente::where('restaurante_id', $restauranteActivo->id);

            if ($request->has('buscar')) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('nombre', 'like', "%{$buscar}%")
                      ->orWhere('apellido', 'like', "%{$buscar}%")
                      ->orWhere('email', 'like', "%{$buscar}%")
                      ->orWhere('telefono', 'like', "%{$buscar}%");
                });
            }

            if ($request->has('activo')) {
                $query->where('activo', $request->activo);
            }

            $clientes = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $clientes->items(),
                'pagination' => [
                    'current_page' => $clientes->currentPage(),
                    'per_page' => $clientes->perPage(),
                    'total' => $clientes->total(),
                    'last_page' => $clientes->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener clientes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_CLIENTES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para crear clientes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'nombre' => 'required|string|max:255',
                'apellido' => 'nullable|string|max:255',
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('clientes')->where(function ($query) use ($restauranteActivo) {
                        return $query->where('restaurante_id', $restauranteActivo->id);
                    })
                ],
                'telefono' => 'nullable|string|max:20',
                'direccion' => 'nullable|string',
                'notas' => 'nullable|string'
            ]);

            DB::beginTransaction();

            $cliente = Cliente::create([
                'restaurante_id' => $restauranteActivo->id,
                'nombre' => $request->nombre,
                'apellido' => $request->apellido,
                'email' => $request->email,
                'telefono' => $request->telefono,
                'direccion' => $request->direccion,
                'fecha_registro' => now(),
                'notas' => $request->notas,
                'activo' => true
            ]);

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'CREAR_CLIENTE',
                    'clientes',
                    $cliente->id,
                    "Cliente creado: {$cliente->nombre_completo}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Cliente creado correctamente',
                'data' => $cliente
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
                'message' => 'Error al crear cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CLIENTE')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $cliente = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->with('ordenes')
                ->findOrFail($id);

            // POLICY: Verificar que puede ver este cliente
            if (Gate::denies('view', $cliente)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a este cliente'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $cliente
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_CLIENTES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar clientes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $cliente = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->findOrFail($id);

            // POLICY: Verificar que puede editar este cliente
            if (Gate::denies('update', $cliente)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a este cliente'
                ], 403);
            }

            $request->validate([
                'nombre' => 'sometimes|string|max:255',
                'apellido' => 'nullable|string|max:255',
                'email' => [
                    'nullable',
                    'email',
                    'max:255',
                    Rule::unique('clientes')->where(function ($query) use ($restauranteActivo) {
                        return $query->where('restaurante_id', $restauranteActivo->id);
                    })->ignore($cliente->id)
                ],
                'telefono' => 'nullable|string|max:20',
                'direccion' => 'nullable|string',
                'notas' => 'nullable|string',
                'activo' => 'sometimes|boolean'
            ]);

            DB::beginTransaction();

            $cliente->update($request->all());

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_CLIENTE',
                    'clientes',
                    $cliente->id,
                    "Cliente actualizado: {$cliente->nombre_completo}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Cliente actualizado correctamente',
                'data' => $cliente
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
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
                'message' => 'Error al actualizar cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('ELIMINAR_CLIENTES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar clientes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $cliente = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->findOrFail($id);

            // POLICY: Verificar que puede eliminar este cliente
            if (Gate::denies('delete', $cliente)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a este cliente'
                ], 403);
            }

            DB::beginTransaction();

            $cliente->delete();

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_CLIENTE',
                    'clientes',
                    $id,
                    "Cliente eliminado: {$cliente->nombre_completo}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Cliente eliminado correctamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function historial(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_HISTORIAL_CLIENTE')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $cliente = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->findOrFail($id);

            // POLICY: Verificar que puede ver este cliente
            if (Gate::denies('view', $cliente)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes acceso a este cliente'
                ], 403);
            }

            $ordenes = $cliente->ordenes()
                ->with('detalles.producto')
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'success' => true,
                'data' => [
                    'cliente' => $cliente,
                    'ordenes' => $ordenes->items(),
                    'estadisticas' => [
                        'total_ordenes' => $cliente->total_compras,
                        'gasto_total' => $cliente->gasto_total,
                        'promedio_por_orden' => $cliente->total_compras > 0 ? $cliente->gasto_total / $cliente->total_compras : 0
                    ]
                ],
                'pagination' => [
                    'current_page' => $ordenes->currentPage(),
                    'per_page' => $ordenes->perPage(),
                    'total' => $ordenes->total(),
                    'last_page' => $ordenes->lastPage()
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Cliente no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener historial',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function selectList(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_CLIENTES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $clientes = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'apellido', 'email']);

            return response()->json([
                'success' => true,
                'data' => $clientes->map(function($cliente) {
                    return [
                        'value' => $cliente->id,
                        'label' => $cliente->nombre_completo . ($cliente->email ? " - {$cliente->email}" : '')
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