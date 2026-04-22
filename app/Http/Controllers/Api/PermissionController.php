<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Permission;
use App\Models\Role;

class PermissionController extends Controller
{
    /**
     * Listar permisos con paginación y filtros
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Verificar permiso (solo administradores/propietarios)
            if (!$user->hasPermission('VER_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver permisos'
                ], 403);
            }

            // Parámetros de paginación
            $perPage = $request->get('per_page', 20);
            $perPage = min($perPage, 100); // Máximo 100 por página
            $page = $request->get('page', 1);

            // Construir query
            $query = Permission::query();

            // FILTROS

            // Búsqueda por nombre
            if ($request->has('nombre') && !empty($request->nombre)) {
                $query->where('nombre', 'like', '%' . $request->nombre . '%');
            }

            // Búsqueda por descripción
            if ($request->has('descripcion') && !empty($request->descripcion)) {
                $query->where('descripcion', 'like', '%' . $request->descripcion . '%');
            }

            // Búsqueda general (nombre o descripción)
            if ($request->has('buscar') && !empty($request->buscar)) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('nombre', 'like', "%{$buscar}%")
                      ->orWhere('descripcion', 'like', "%{$buscar}%");
                });
            }

            // Filtro por rol (permisos de un rol específico)
            if ($request->has('rol_id')) {
                $query->whereHas('roles', function($q) use ($request) {
                    $q->where('role_id', $request->rol_id);
                });
            }

            // Ordenamiento
            $orderBy = $request->get('order_by', 'nombre');
            $orderDir = $request->get('order_dir', 'asc');
            
            $allowedOrderFields = ['id', 'nombre', 'descripcion', 'created_at'];
            if (in_array($orderBy, $allowedOrderFields)) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            }

            // Ejecutar paginación
            $permissions = $query->paginate($perPage, ['*'], 'page', $page);

            // Transformar datos
            $permissionsData = $permissions->map(function($permission) {
                return [
                    'id' => $permission->id,
                    'nombre' => $permission->nombre,
                    'descripcion' => $permission->descripcion,
                    'created_at' => $permission->created_at,
                    'created_at_formateado' => $permission->created_at->format('d/m/Y H:i'),
                    'updated_at' => $permission->updated_at,
                    'roles_count' => $permission->roles()->count(),
                    'roles' => $permission->roles->map(function($rol) {
                        return [
                            'id' => $rol->id,
                            'nombre' => $rol->nombre
                        ];
                    })
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Permisos obtenidos correctamente',
                'data' => $permissionsData,
                'pagination' => [
                    'current_page' => $permissions->currentPage(),
                    'per_page' => $permissions->perPage(),
                    'total' => $permissions->total(),
                    'last_page' => $permissions->lastPage(),
                    'from' => $permissions->firstItem(),
                    'to' => $permissions->lastItem(),
                    'next_page_url' => $permissions->nextPageUrl(),
                    'prev_page_url' => $permissions->previousPageUrl()
                ],
                'filters' => [
                    'nombre' => $request->nombre ?? null,
                    'buscar' => $request->buscar ?? null,
                    'rol_id' => $request->rol_id ?? null
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo permiso
     */
    public function store(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('CREAR_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para crear permisos'
                ], 403);
            }

            $request->validate([
                'nombre' => 'required|string|max:100|unique:permissions,nombre|regex:/^[A-Z_]+$/',
                'descripcion' => 'nullable|string|max:255',
            ], [
                'nombre.regex' => 'El nombre del permiso solo puede contener mayúsculas y guiones bajos (ej: VER_USUARIOS)'
            ]);

            $permission = Permission::create([
                'nombre' => strtoupper($request->nombre),
                'descripcion' => $request->descripcion
            ]);

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'CREAR_PERMISO',
                    'permissions',
                    $permission->id,
                    "Permiso creado: {$permission->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Permiso creado correctamente',
                'data' => [
                    'id' => $permission->id,
                    'nombre' => $permission->nombre,
                    'descripcion' => $permission->descripcion,
                    'created_at' => $permission->created_at
                ]
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al crear permiso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un permiso específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $permission = Permission::with('roles')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $permission->id,
                    'nombre' => $permission->nombre,
                    'descripcion' => $permission->descripcion,
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                    'roles' => $permission->roles->map(function($rol) {
                        return [
                            'id' => $rol->id,
                            'nombre' => $rol->nombre
                        ];
                    })
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permiso no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permiso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Actualizar un permiso
     */
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para editar permisos'
                ], 403);
            }

            $permission = Permission::findOrFail($id);

            $request->validate([
                'nombre' => 'sometimes|string|max:100|unique:permissions,nombre,' . $id . '|regex:/^[A-Z_]+$/',
                'descripcion' => 'nullable|string|max:255',
            ]);

            $data = [];
            if ($request->has('nombre')) {
                $data['nombre'] = strtoupper($request->nombre);
            }
            if ($request->has('descripcion')) {
                $data['descripcion'] = $request->descripcion;
            }

            $permission->update($data);

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'EDITAR_PERMISO',
                    'permissions',
                    $permission->id,
                    "Permiso actualizado: {$permission->nombre}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Permiso actualizado correctamente',
                'data' => [
                    'id' => $permission->id,
                    'nombre' => $permission->nombre,
                    'descripcion' => $permission->descripcion
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permiso no encontrado'
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar permiso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Eliminar un permiso
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('ELIMINAR_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar permisos'
                ], 403);
            }

            $permission = Permission::findOrFail($id);

            // Verificar si el permiso está siendo usado por algún rol
            $rolesCount = $permission->roles()->count();
            if ($rolesCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede eliminar el permiso porque está asignado a {$rolesCount} rol(es)",
                    'roles' => $permission->roles->pluck('nombre')
                ], 409);
            }

            $nombrePermiso = $permission->nombre;
            $permission->delete();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_PERMISO',
                    'permissions',
                    $id,
                    "Permiso eliminado: {$nombrePermiso}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Permiso eliminado correctamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Permiso no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar permiso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar permiso a roles
     */
    public function assignToRoles(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $request->validate([
                'role_ids' => 'required|array',
                'role_ids.*' => 'exists:roles,id'
            ]);

            $permission = Permission::findOrFail($id);
            
            // Sincronizar roles (esto reemplazará las asignaciones existentes)
            $permission->roles()->sync($request->role_ids);

            $rolesAsignados = Role::whereIn('id', $request->role_ids)->get();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ASIGNAR_PERMISO',
                    'permissions',
                    $permission->id,
                    "Permiso {$permission->nombre} asignado a " . $rolesAsignados->pluck('nombre')->join(', ')
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Permiso asignado a roles correctamente',
                'data' => [
                    'permission' => [
                        'id' => $permission->id,
                        'nombre' => $permission->nombre
                    ],
                    'roles' => $rolesAsignados->map(function($rol) {
                        return [
                            'id' => $rol->id,
                            'nombre' => $rol->nombre
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar permiso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos agrupados por prefijo
     */
    public function grouped(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $permissions = Permission::all();
            
            // Agrupar por el prefijo (antes del primer _)
            $grouped = $permissions->groupBy(function($permiso) {
                $partes = explode('_', $permiso->nombre);
                return $partes[0] ?? 'OTROS';
            })->map(function($grupo) {
                return $grupo->map(function($permiso) {
                    return [
                        'id' => $permiso->id,
                        'nombre' => $permiso->nombre,
                        'descripcion' => $permiso->descripcion
                    ];
                });
            });

            return response()->json([
                'success' => true,
                'data' => $grouped
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos agrupados',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener permisos por rol
     */
    public function byRole(Request $request, $rolId)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_PERMISOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $rol = Role::findOrFail($rolId);
            
            $permissions = $rol->permissions;

            return response()->json([
                'success' => true,
                'data' => [
                    'rol' => [
                        'id' => $rol->id,
                        'nombre' => $rol->nombre
                    ],
                    'permissions' => $permissions->map(function($permiso) {
                        return [
                            'id' => $permiso->id,
                            'nombre' => $permiso->nombre,
                            'descripcion' => $permiso->descripcion
                        ];
                    })
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener permisos del rol',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}