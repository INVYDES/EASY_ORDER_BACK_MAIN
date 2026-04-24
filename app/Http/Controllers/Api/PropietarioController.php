<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Propietario;
use App\Models\User;
use App\Models\Restaurante;
use App\Models\Licencia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class PropietarioController extends Controller
{
    // ══════════════════════════════════════════════════════════════
    // REGISTRO COMPLETO (público) — usado por /propietarios y /propietarios-completo
    // ══════════════════════════════════════════════════════════════
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // Datos personales
            'nombre'              => 'required|string|max:100',
            'apellido'            => 'required|string|max:100',
            'correo'              => 'required|email|unique:propietarios,correo|unique:users,email',
            'password'            => 'required|string|min:8',

            // Datos fiscales (nullable según BD)
            'rfc'                 => 'nullable|string|max:20|unique:propietarios,rfc',
            'regimen_fiscal'      => 'nullable|string|max:10',

            // Datos del restaurante (solo telefono es obligatorio para el flujo)
            'telefono'            => 'nullable|string|max:20',
            'calle'               => 'nullable|string|max:150',
            'colonia'             => 'nullable|string|max:150',
            'codigo_postal'       => 'nullable|string|max:5',
            'ciudad'              => 'nullable|string|max:100',
            'estado'              => 'nullable|string|max:100',

            // Nombre del restaurante y horario (opcionales con default)
            'restaurante_nombre'  => 'nullable|string|max:150',
            'restaurante_horario' => 'nullable|string|max:100',

            // Licencia
            'tipo_licencia' => 'required|string|in:mensual-1,mensual-2,anual-1,anual-2,prueba-1',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // 1. Propietario
            $propietario = Propietario::create([
                'nombre'         => $request->nombre,
                'apellido'       => $request->apellido,
                'correo'         => $request->correo,
                'telefono'       => $request->telefono,
                'rfc'            => $request->rfc ? strtoupper($request->rfc) : null,
                'regimen_fiscal' => $request->regimen_fiscal,
            ]);

            // 2. Usuario principal
            $username = $this->generateUsername($request->nombre, $request->apellido);
            $user = User::create([
                'propietario_id' => $propietario->id,
                'name'           => $request->nombre . ' ' . $request->apellido,
                'email'          => $request->correo,
                'username'       => $username,
                'password'       => Hash::make($request->password),
            ]);
            $user->roles()->attach(1); // Rol PROPIETARIO

            // 3. Restaurante con todos los campos de dirección
            $restaurante = Restaurante::create([
                'propietario_id' => $propietario->id,
                // Usa el nombre enviado por el frontend, o un default si no viene
                'nombre'         => $request->restaurante_nombre ?? 'Mi Restaurante',
                'telefono'       => $request->telefono,
                'calle'          => $request->calle,
                'colonia'        => $request->colonia,
                'codigo_postal'  => $request->codigo_postal,
                'ciudad'         => $request->ciudad,
                'estado'         => $request->estado,
                'horario'        => $request->restaurante_horario ?? null,
                'activo'         => true,
            ]);
            $user->restaurantes()->attach($restaurante->id);
            $user->update(['restaurante_activo' => $restaurante->id]);

            // 4. Licencia
            $licenciaInfo = $this->asignarLicencia($propietario->id, $request->tipo_licencia);

            DB::commit();

            $this->registrarLogPublico(
                'REGISTRO_PROPIETARIO',
                'propietarios',
                $propietario->id,
                "Nuevo propietario: {$propietario->nombre} {$propietario->apellido} - {$propietario->correo}",
                $request->ip()
            );

            $token = $user->createToken('auth_token_' . $user->id)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Registro completado exitosamente',
                'data'    => [
                    'user'        => [
                        'id'       => $user->id,
                        'name'     => $user->name,
                        'email'    => $user->email,
                        'username' => $user->username,
                    ],
                    'token'       => $token,
                    'restaurante' => [
                        'id'     => $restaurante->id,
                        'nombre' => $restaurante->nombre,
                    ],
                    'licencia'    => $licenciaInfo,
                ],
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            $this->registrarLogPublico(
                'ERROR_REGISTRO', 'propietarios', null,
                "Error: " . $e->getMessage(), $request->ip()
            );
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // LISTAR
    // ══════════════════════════════════════════════════════════════
    public function index(Request $request)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('VER_PROPIETARIOS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para ver propietarios'], 403);
            }

            $perPage = min($request->get('per_page', 15), 50);

            $query = $user->roles()->where('nombre', 'PROPIETARIO')->exists()
                ? Propietario::where('id', $user->propietario_id)
                : Propietario::query();

            if ($request->filled('buscar')) {
                $b = $request->buscar;
                $query->where(fn($q) => $q
                    ->where('nombre',    'like', "%{$b}%")
                    ->orWhere('apellido', 'like', "%{$b}%")
                    ->orWhere('correo',   'like', "%{$b}%")
                    ->orWhere('rfc',      'like', "%{$b}%")
                );
            }
            if ($request->filled('regimen_fiscal')) {
                $query->where('regimen_fiscal', $request->regimen_fiscal);
            }

            $propietarios = $query->withCount(['restaurantes', 'users'])->paginate($perPage);

            $this->registrarLog($user, 'VER_PROPIETARIOS', 'propietarios', null, 'Consultó listado de propietarios');

            return response()->json([
                'success'    => true,
                'data'       => $propietarios->items(),
                'pagination' => [
                    'current_page' => $propietarios->currentPage(),
                    'per_page'     => $propietarios->perPage(),
                    'total'        => $propietarios->total(),
                    'last_page'    => $propietarios->lastPage(),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener propietarios', 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // VER UNO
    // ══════════════════════════════════════════════════════════════
    public function show(Request $request, $id)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('VER_PROPIETARIOS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }
            if ($user->propietario_id != $id && !$user->hasPermission('VER_TODOS_PROPIETARIOS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para ver este propietario'], 403);
            }

            $propietario = Propietario::with([
                'users.roles',
                'users.restauranteActivo',
                'restaurantes',
                'licencias.licencia',
            ])->findOrFail($id);

            $usuarios = $propietario->users->map(fn($u) => [
                'id'       => $u->id,
                'name'     => $u->name,
                'email'    => $u->email,
                'username' => $u->username,
                'roles'    => $u->roles->map(fn($r) => ['id' => $r->id, 'nombre' => $r->nombre]),
                'restaurante_activo' => $u->restauranteActivo ? [
                    'id'     => $u->restauranteActivo->id,
                    'nombre' => $u->restauranteActivo->nombre
                ] : null,
            ]);

            $this->registrarLog($user, 'VER_PROPIETARIO', 'propietarios', $id, "Vio detalles del propietario ID: {$id}");

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'             => $propietario->id,
                    'nombre'         => $propietario->nombre,
                    'apellido'       => $propietario->apellido,
                    'correo'         => $propietario->correo,
                    'telefono'       => $propietario->telefono,
                    'rfc'            => $propietario->rfc,
                    'regimen_fiscal' => $propietario->regimen_fiscal,
                    'restaurantes'   => $propietario->restaurantes,
                    'usuarios'       => $usuarios,
                    'licencias'      => $propietario->licencias,
                ],
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener propietario', 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ACTUALIZAR
    // ══════════════════════════════════════════════════════════════
    public function update(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('EDITAR_PROPIETARIOS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $propietario = Propietario::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'nombre'         => 'sometimes|string|max:100',
                'apellido'       => 'sometimes|string|max:100',
                'correo'         => 'sometimes|email|unique:propietarios,correo,' . $id,
                'telefono'       => 'nullable|string|max:20',
                'rfc'            => 'nullable|string|max:20|unique:propietarios,rfc,' . $id,
                'regimen_fiscal' => 'nullable|string|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();
            $propietario->update($request->only(['nombre', 'apellido', 'correo', 'telefono', 'rfc', 'regimen_fiscal']));
            DB::commit();

            $this->registrarLog($user, 'EDITAR_PROPIETARIO', 'propietarios', $id, "Actualizó propietario ID: {$id}");

            return response()->json(['success' => true, 'message' => 'Propietario actualizado correctamente', 'data' => $propietario]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar propietario', 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ELIMINAR
    // ══════════════════════════════════════════════════════════════
    public function destroy(Request $request, $id)
    {
        try {
            $user = $request->user();
            if (!$user->hasPermission('ELIMINAR_PROPIETARIOS')) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso'], 403);
            }

            $propietario    = Propietario::findOrFail($id);
            $nombreCompleto = $propietario->nombre_completo ?? $propietario->nombre . ' ' . $propietario->apellido;

            if ($propietario->restaurantes()->count() > 0) {
                return response()->json(['success' => false, 'message' => 'No se puede eliminar porque tiene restaurantes asociados'], 409);
            }
            if ($propietario->users()->count() > 0) {
                return response()->json(['success' => false, 'message' => 'No se puede eliminar porque tiene usuarios asociados'], 409);
            }

            $propietario->delete();
            $this->registrarLog($user, 'ELIMINAR_PROPIETARIO', 'propietarios', $id, "Eliminó propietario: {$nombreCompleto}");

            return response()->json(['success' => true, 'message' => 'Propietario eliminado correctamente']);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar propietario', 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // DASHBOARD
    // ══════════════════════════════════════════════════════════════
    public function dashboard(Request $request)
    {
        try {
            $user = $request->user();

            $propietario = $user->roles()->where('nombre', 'PROPIETARIO')->exists()
                ? Propietario::find($user->propietario_id)
                : Propietario::find(
                    $request->validate(['propietario_id' => 'required|exists:propietarios,id'])['propietario_id']
                  );

            if (!$propietario) {
                return response()->json(['success' => false, 'message' => 'Propietario no encontrado'], 404);
            }

            $licenciaActiva = DB::table('propietario_licencia')
                ->join('licencias', 'propietario_licencia.licencia_id', '=', 'licencias.id')
                ->where('propietario_licencia.propietario_id', $propietario->id)
                ->where('propietario_licencia.estado', 'ACTIVA')
                ->where('propietario_licencia.fecha_expiracion', '>', now())
                ->select('licencias.*', 'propietario_licencia.fecha_inicio', 'propietario_licencia.fecha_expiracion')
                ->first();

            $restaurantes = $propietario->restaurantes;
            $usuarios     = $propietario->users;

            $this->registrarLog($user, 'VER_DASHBOARD', 'propietarios', $propietario->id, 'Consultó dashboard');

            return response()->json([
                'success' => true,
                'data'    => [
                    'propietario' => [
                        'id'              => $propietario->id,
                        'nombre_completo' => $propietario->nombre_completo ?? $propietario->nombre . ' ' . $propietario->apellido,
                        'correo'          => $propietario->correo,
                    ],
                    'licencia' => $licenciaActiva ? [
                        'tipo'             => $licenciaActiva->tipo,
                        'max_restaurantes' => $licenciaActiva->max_restaurantes,
                        'max_usuarios'     => $licenciaActiva->max_usuarios,
                        'fecha_expiracion' => $licenciaActiva->fecha_expiracion,
                        'dias_restantes'   => now()->diffInDays($licenciaActiva->fecha_expiracion, false),
                    ] : null,
                    'estadisticas' => [
                        'total_restaurantes'     => $restaurantes->count(),
                        'restaurantes_restantes' => $licenciaActiva ? max(0, $licenciaActiva->max_restaurantes - $restaurantes->count()) : 0,
                        'total_usuarios'         => $usuarios->count(),
                        'usuarios_restantes'     => $licenciaActiva ? max(0, $licenciaActiva->max_usuarios - $usuarios->count()) : 0,
                    ],
                    'restaurantes' => $restaurantes->map(fn($r) => [
                        'id'     => $r->id,
                        'nombre' => $r->nombre,
                        'ciudad' => $r->ciudad,
                        'estado' => $r->estado,
                    ]),
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener dashboard', 'error' => $e->getMessage()], 500);
        }
    }

    // ══════════════════════════════════════════════════════════════
    // PRIVADOS
    // ══════════════════════════════════════════════════════════════

    private function asignarLicencia($propietarioId, $tipoLicencia)
    {
        $map = [
            'mensual-1' => ['tipo' => 'MENSUAL', 'max_rest' => 1, 'meses' => 1],
            'mensual-2' => ['tipo' => 'MENSUAL', 'max_rest' => 2, 'meses' => 1],
            'anual-1'   => ['tipo' => 'ANUAL',   'max_rest' => 1, 'meses' => 12],
            'anual-2'   => ['tipo' => 'ANUAL',   'max_rest' => 2, 'meses' => 12],
        ];
        $config = $map[$tipoLicencia] ?? $map['mensual-1'];

        $licencia = Licencia::where('tipo', $config['tipo'])
            ->where('max_restaurantes', $config['max_rest'])
            ->where('activo', true)
            ->first();

        if (!$licencia) {
            throw new \Exception('Tipo de licencia no disponible: ' . $tipoLicencia);
        }

        DB::table('propietario_licencia')->insert([
            'propietario_id'   => $propietarioId,
            'licencia_id'      => $licencia->id,
            'fecha_inicio'     => now(),
            'fecha_expiracion' => now()->addMonths($config['meses']),
            'estado'           => 'ACTIVA',
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return [
            'licencia_id'      => $licencia->id,
            'tipo'             => $licencia->tipo,
            'max_restaurantes' => $licencia->max_restaurantes,
            'fecha_expiracion' => now()->addMonths($config['meses']),
        ];
    }

    private function generateUsername($nombre, $apellido)
    {
        $base     = strtolower(preg_replace('/[^a-z0-9]/', '', substr($nombre, 0, 1) . $apellido));
        $username = $base;
        $counter  = 1;
        while (User::where('username', $username)->exists()) {
            $username = $base . $counter++;
        }
        return $username;
    }

    private function registrarLogPublico($accion, $tabla, $registroId = null, $descripcion = null, $ip = null)
    {
        try {
            DB::table('logs')->insert([
                'user_id'        => null,
                'accion'         => $accion,
                'tabla_afectada' => $tabla,
                'registro_id'    => $registroId,
                'descripcion'    => $descripcion,
                'ip_address'     => $ip ?? request()->ip(),
                'created_at'     => now(),
            ]);
        } catch (\Exception $e) {
            \Log::error('Error log público: ' . $e->getMessage());
        }
    }

    private function registrarLog($user, $accion, $tabla, $registroId = null, $descripcion = null)
    {
        try {
            if ($user && method_exists($user, 'logAction')) {
                $user->logAction($accion, $tabla, $registroId, $descripcion);
            } else {
                DB::table('logs')->insert([
                    'user_id'        => $user?->id,
                    'accion'         => $accion,
                    'tabla_afectada' => $tabla,
                    'registro_id'    => $registroId,
                    'descripcion'    => $descripcion,
                    'ip_address'     => request()->ip(),
                    'created_at'     => now(),
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Error log: ' . $e->getMessage());
        }
    }
}