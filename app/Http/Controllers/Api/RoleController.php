<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Support\Facades\DB;

class RoleController extends Controller
{
    /**
     * Listar roles con paginación y filtros
     */
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            
            // Verificar permiso
            if (!$user->hasPermission('VER_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para ver roles'
                ], 403);
            }

            // Parámetros de paginación
            $perPage = $request->get('per_page', 15);
            $perPage = min($perPage, 50);
            $page = $request->get('page', 1);

            // Construir query
            $query = Role::with('permissions');

            // FILTROS

            // Búsqueda por nombre
            if ($request->has('nombre') && !empty($request->nombre)) {
                $query->where('nombre', 'like', '%' . $request->nombre . '%');
            }

            // Búsqueda por descripción
            if ($request->has('descripcion') && !empty($request->descripcion)) {
                $query->where('descripcion', 'like', '%' . $request->descripcion . '%');
            }

            // Búsqueda general
            if ($request->has('buscar') && !empty($request->buscar)) {
                $buscar = $request->buscar;
                $query->where(function($q) use ($buscar) {
                    $q->where('nombre', 'like', "%{$buscar}%")
                      ->orWhere('descripcion', 'like', "%{$buscar}%");
                });
            }

            // Filtro por permiso específico
            if ($request->has('permission_id')) {
                $query->whereHas('permissions', function($q) use ($request) {
                    $q->where('permission_id', $request->permission_id);
                });
            }

            // Filtro por cantidad de usuarios
            if ($request->has('min_usuarios')) {
                $query->has('users', '>=', $request->min_usuarios);
            }

            // Ordenamiento
            $orderBy = $request->get('order_by', 'nombre');
            $orderDir = $request->get('order_dir', 'asc');
            
            $allowedOrderFields = ['id', 'nombre', 'descripcion', 'created_at'];
            if (in_array($orderBy, $allowedOrderFields)) {
                $query->orderBy($orderBy, $orderDir === 'asc' ? 'asc' : 'desc');
            }

            // Cargar conteos
            $query->withCount('users');

            // Ejecutar paginación
            $roles = $query->paginate($perPage, ['*'], 'page', $page);

            // Transformar datos
            $rolesData = $roles->map(function($role) {
                return [
                    'id' => $role->id,
                    'nombre' => $role->nombre,
                    'nombre_formateado' => ucfirst(strtolower($role->nombre)),
                    'descripcion' => $role->descripcion,
                    'users_count' => $role->users_count,
                    'permissions_count' => $role->permissions->count(),
                    'permissions' => $role->permissions->map(function($perm) {
                        return [
                            'id' => $perm->id,
                            'nombre' => $perm->nombre,
                            'descripcion' => $perm->descripcion
                        ];
                    }),
                    'created_at' => $role->created_at,
                    'created_at_formateado' => $role->created_at->format('d/m/Y H:i'),
                    'updated_at' => $role->updated_at
                ];
            });

            // Estadísticas
            $estadisticas = [
                'total_roles' => Role::count(),
                'total_permisos' => Permission::count(),
                'roles_con_mas_usuarios' => Role::withCount('users')
                    ->orderByDesc('users_count')
                    ->limit(5)
                    ->get(['nombre', 'users_count'])
            ];

            return response()->json([
                'success' => true,
                'message' => 'Roles obtenidos correctamente',
                'data' => $rolesData,
                'pagination' => [
                    'current_page' => $roles->currentPage(),
                    'per_page' => $roles->perPage(),
                    'total' => $roles->total(),
                    'last_page' => $roles->lastPage(),
                    'from' => $roles->firstItem(),
                    'to' => $roles->lastItem(),
                    'next_page_url' => $roles->nextPageUrl(),
                    'prev_page_url' => $roles->previousPageUrl()
                ],
                'filters' => [
                    'nombre' => $request->nombre ?? null,
                    'buscar' => $request->buscar ?? null,
                    'permission_id' => $request->permission_id ?? null
                ],
                'estadisticas' => $estadisticas
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener roles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mostrar un rol específico
     */
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $role = Role::with(['permissions', 'users'])->findOrFail($id);

            // Agrupar permisos por categoría (prefijo)
            $permissionsByModule = $role->permissions
                ->groupBy(function($perm) {
                    $parts = explode('_', $perm->nombre);
                    return $parts[0] ?? 'OTROS';
                })
                ->map(function($perms) {
                    return $perms->map(function($perm) {
                        return [
                            'id' => $perm->id,
                            'nombre' => $perm->nombre,
                            'descripcion' => $perm->descripcion
                        ];
                    });
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $role->id,
                    'nombre' => $role->nombre,
                    'descripcion' => $role->descripcion,
                    'users_count' => $role->users->count(),
                    'users' => $role->users->take(10)->map(function($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => $user->username,
                            'email' => $user->email
                        ];
                    }),
                    'permissions_count' => $role->permissions->count(),
                    'permissions_by_module' => $permissionsByModule,
                    'created_at' => $role->created_at,
                    'created_at_formateado' => $role->created_at->format('d/m/Y H:i'),
                    'updated_at' => $role->updated_at
                ]
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener rol',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Crear un nuevo rol
     */
    /**
 * Crear un nuevo rol con permisos automáticos
 */
public function store(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user->hasPermission('CREAR_ROLES')) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para crear roles'
            ], 403);
        }

        $request->validate([
            'nombre' => 'required|string|max:100|unique:roles|regex:/^[A-Z_]+$/',
            'descripcion' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        DB::beginTransaction();

        // Crear rol
        $role = Role::create([
            'nombre' => strtoupper($request->nombre),
            'descripcion' => $request->descripcion
        ]);

        // =============================================================
        // 👇 ASIGNACIÓN AUTOMÁTICA DE PERMISOS
        // =============================================================
        $this->assignDefaultPermissions($role, $request->permissions ?? []);
        
        // Si se enviaron permisos manualmente, agregarlos
        if ($request->has('permissions') && !empty($request->permissions)) {
            $role->permissions()->syncWithoutDetaching($request->permissions);
        }

        DB::commit();

        // Registrar en log
        if (method_exists($user, 'logAction')) {
            $user->logAction(
                'CREAR_ROL',
                'roles',
                $role->id,
                "Rol creado: {$role->nombre}"
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Rol creado correctamente',
            'data' => [
                'id' => $role->id,
                'nombre' => $role->nombre,
                'descripcion' => $role->descripcion,
                'permissions' => $role->permissions->pluck('id'),
                'created_at' => $role->created_at
            ]
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
            'message' => 'Error al crear rol',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * Asignar permisos por defecto según el nombre del rol
 */
private function assignDefaultPermissions($role, $manualPermissions = [])
{
    // Configuración de permisos por defecto para roles estándar
    $defaultPermissions = [
        'ADMIN' => [
            1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18, // CRUD básico
            24,25,26,27,28, // Clientes
            29,30, // Reportes
            31,32,33,34,37, // Propietarios y empleados
            36, // Cerrar caja admin
            38,39,40,41,42, // Categorías
            43,44, // Logs
            45,46,47,48, // Roles
            49,50,51,52, // Permisos
            53, // Ver menú
            57, // Historial cliente
            58,59,60,61, // Caja
            62,63,64,65,66, // Licencias
        ],
        'MESERO' => [
            1,5,9,10,11,12,13,14,24,25,26,27,29,38,39,53,57
        ],
        'COCINA' => [
            1,5,9,12,38,39,53
        ],
        'CAJA' => [
            1,5,9,12,13,14,24,25,29,30,38,39,53,57,58,59,60,61
        ],
        'CLIENTE' => [
            53,54,55,56
        ],
        'PROPIETARIO' => 'all', // Marca especial
    ];
    
    $permisos = $defaultPermissions[strtoupper($role->nombre)] ?? [];
    
    // Si es PROPIETARIO, asignar todos los permisos existentes
    if ($permisos === 'all') {
        $permisos = Permission::pluck('id')->toArray();
    }
    
    // Asignar permisos por defecto
    if (!empty($permisos)) {
        $role->permissions()->sync($permisos);
    }
    
    return $role;
}

    /**
     * Actualizar un rol
     */
   /**
 * Actualizar un rol
 */
public function update(Request $request, $id)
{
    try {
        $user = $request->user();
        
        if (!$user->hasPermission('EDITAR_ROLES')) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para editar roles'
            ], 403);
        }

        $role = Role::findOrFail($id);

        // No permitir editar roles del sistema
        if (in_array($role->nombre, ['PROPIETARIO', 'ADMIN'])) {
            return response()->json([
                'success' => false,
                'message' => 'No se puede editar este rol del sistema'
            ], 403);
        }

        $request->validate([
            'nombre' => 'sometimes|string|max:100|unique:roles,nombre,' . $id . '|regex:/^[A-Z_]+$/',
            'descripcion' => 'nullable|string|max:255',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'exists:permissions,id'
        ]);

        DB::beginTransaction();

        $oldName = $role->nombre;
        
        // Actualizar datos básicos
        $role->update([
            'nombre' => $request->has('nombre') ? strtoupper($request->nombre) : $role->nombre,
            'descripcion' => $request->has('descripcion') ? $request->descripcion : $role->descripcion
        ]);

        // Actualizar permisos si se proporcionaron
        if ($request->has('permissions')) {
            // Si es un rol estándar y se están modificando permisos manualmente
            $role->permissions()->sync($request->permissions);
        } elseif ($oldName !== $role->nombre) {
            // Si cambió el nombre, actualizar permisos automáticos
            $this->assignDefaultPermissions($role);
        }

        DB::commit();

        // Registrar en log
        if (method_exists($user, 'logAction')) {
            $user->logAction(
                'EDITAR_ROL',
                'roles',
                $role->id,
                "Rol actualizado: {$role->nombre}"
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado correctamente',
            'data' => [
                'id' => $role->id,
                'nombre' => $role->nombre,
                'descripcion' => $role->descripcion,
                'permissions' => $role->permissions->pluck('id')
            ]
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Rol no encontrado'
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
            'message' => 'Error al actualizar rol',
            'error' => $e->getMessage()
        ], 500);
    }
}
    /**
     * Eliminar un rol
     */
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('ELIMINAR_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para eliminar roles'
                ], 403);
            }

            $role = Role::findOrFail($id);

            // No permitir eliminar roles del sistema
            if (in_array($role->nombre, ['PROPIETARIO', 'ADMIN', 'MESERO', 'COCINA', 'CAJA'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar este rol del sistema'
                ], 403);
            }

            // Verificar si tiene usuarios asignados
            if ($role->users()->count() > 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No se puede eliminar el rol porque tiene usuarios asignados'
                ], 409);
            }

            $nombreRol = $role->nombre;
            $role->delete();

            // Registrar en log
            if (method_exists($user, 'logAction')) {
                $user->logAction(
                    'ELIMINAR_ROL',
                    'roles',
                    $id,
                    "Rol eliminado: {$nombreRol}"
                );
            }

            return response()->json([
                'success' => true,
                'message' => 'Rol eliminado correctamente'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Rol no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar rol',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Asignar permisos a un rol
     */
    public function assignPermissions(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $role = Role::findOrFail($id);

            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id',
                'sync' => 'nullable|boolean' // true = reemplazar, false = agregar
            ]);

            if ($request->sync) {
                // Reemplazar todos los permisos
                $role->permissions()->sync($request->permissions);
                $message = 'Permisos actualizados correctamente';
            } else {
                // Agregar permisos (sin eliminar los existentes)
                $role->permissions()->syncWithoutDetaching($request->permissions);
                $message = 'Permisos asignados correctamente';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'role' => [
                        'id' => $role->id,
                        'nombre' => $role->nombre
                    ],
                    'permissions' => $role->permissions->pluck('id')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al asignar permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Quitar permisos de un rol
     */
    public function removePermissions(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('EDITAR_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $role = Role::findOrFail($id);

            $request->validate([
                'permissions' => 'required|array',
                'permissions.*' => 'exists:permissions,id'
            ]);

            $role->permissions()->detach($request->permissions);

            return response()->json([
                'success' => true,
                'message' => 'Permisos removidos correctamente',
                'data' => [
                    'role' => [
                        'id' => $role->id,
                        'nombre' => $role->nombre
                    ],
                    'permissions' => $role->permissions->pluck('id')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al remover permisos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener usuarios de un rol
     */
    public function users(Request $request, $id)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $role = Role::with('users')->findOrFail($id);

            $perPage = $request->get('per_page', 15);
            $users = $role->users()->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'role' => [
                        'id' => $role->id,
                        'nombre' => $role->nombre
                    ],
                    'users' => $users->map(function($user) {
                        return [
                            'id' => $user->id,
                            'name' => $user->name,
                            'username' => $user->username,
                            'email' => $user->email,
                            'propietario_id' => $user->propietario_id
                        ];
                    })
                ],
                'pagination' => [
                    'current_page' => $users->currentPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                    'last_page' => $users->lastPage()
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener usuarios',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtener lista de roles para selects
     */
    public function selectList(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $roles = Role::orderBy('nombre')
                ->get(['id', 'nombre', 'descripcion']);

            return response()->json([
                'success' => true,
                'data' => $roles->map(function($role) {
                    return [
                        'value' => $role->id,
                        'label' => $role->nombre,
                        'descripcion' => $role->descripcion
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

    /**
     * Obtener todos los permisos disponibles agrupados
     */
    public function availablePermissions(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user->hasPermission('VER_ROLES')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso'
                ], 403);
            }

            $permissions = Permission::orderBy('nombre')->get();

            // Agrupar por módulo (prefijo)
            $grouped = $permissions->groupBy(function($perm) {
                $parts = explode('_', $perm->nombre);
                return $parts[0] ?? 'OTROS';
            })->map(function($perms) {
                return $perms->map(function($perm) {
                    return [
                        'id' => $perm->id,
                        'nombre' => $perm->nombre,
                        'descripcion' => $perm->descripcion
                    ];
                })->values();
            });

            return response()->json([
                'success' => true,
                'data' => $grouped
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
 * Sincronizar todos los roles con sus permisos por defecto
 * Este método se puede ejecutar vía comando Artisan
 */
public function syncAllRoles()
{
    try {
        $roles = Role::all();
        $results = [];
        
        foreach ($roles as $role) {
            $this->assignDefaultPermissions($role);
            $results[] = [
                'role' => $role->nombre,
                'permissions_count' => $role->permissions()->count()
            ];
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Roles sincronizados correctamente',
            'data' => $results
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Error al sincronizar roles',
            'error' => $e->getMessage()
        ], 500);
    }
}
}