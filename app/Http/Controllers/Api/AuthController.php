<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Propietario;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * REGISTRO DE CLIENTES
     * Ruta pública — el cliente se registra solo con nombre, email y teléfono.
     * Se crea un User con rol CLIENTE (id=6), sin propietario ni restaurante.
     * Puede ver todos los menús de todos los restaurantes.
     */
    public function registerCliente(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre'   => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'telefono' => 'nullable|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.unique'        => 'Ya existe una cuenta con ese correo.',
            'password.min'        => 'La contraseña debe tener al menos 6 caracteres.',
            'password.confirmed'  => 'Las contraseñas no coinciden.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Generar username único a partir del email
            $baseUsername = strtolower(explode('@', $request->email)[0]);
            $username     = $baseUsername;
            $i = 1;
            while (User::where('username', $username)->exists()) {
                $username = $baseUsername . $i++;
            }

            $user = User::create([
                'name'       => $request->nombre,
                'email'      => $request->email,
                'username'   => $username,
                'password'   => Hash::make($request->password),
                'telefono'   => $request->telefono,
                // Sin propietario_id — cliente independiente
                // Sin restaurante_activo — puede ver todos
            ]);

            // Asignar rol CLIENTE (id = 6) - Ver CLAUDE.md
            $rolCliente = Role::whereRaw('LOWER(nombre) = ?', ['cliente'])->first()
                ?? Role::find(6);

            if (!$rolCliente) {
                throw new \Exception('Rol CLIENTE no encontrado. Ejecuta seeders.');
            }

            $user->roles()->attach($rolCliente->id);

            DB::commit();

            $user->load('roles');
            $token = $user->createToken('cliente_' . $user->id)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => '¡Cuenta creada! Ya puedes explorar el menú.',
                'user'    => $user,
                'token'   => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la cuenta',
                'error'   => config('app.debug') ? $e->getMessage() : 'Error interno',
            ], 500);
        }
    }
    /**
     * Login de usuarios - Permite login con email o username
     */
   public function login(Request $request)
{
    $request->validate([
        'login' => 'required|string',
        'password' => 'required|string'
    ]);

    $login = $request->login;
    $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

    $user = User::where($field, $login)->first();

    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Credenciales incorrectas'
        ], 401);
    }

    $user->load(['roles', 'restauranteActivo']); // ✅ AGREGA ESTA LÍNEA

    $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

    return response()->json([
        'success' => true,
        'message' => 'Login exitoso',
        'user' => $user,
        'token' => $token
    ]);
}

    /**
     * REGISTRO DE NUEVOS USUARIOS
     */
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'username' => 'required|string|max:50|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'rol_id' => 'required|exists:roles,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Crear propietario automáticamente para nuevos dueños
            $propietario = Propietario::create([
                'nombre' => $request->name,
                'email' => $request->email
            ]);

            // Crear usuario
            $user = User::create([
                'propietario_id' => $propietario->id,
                'name' => $request->name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password)
            ]);

            // Asignar rol
            $user->roles()->attach($request->rol_id);

            DB::commit();

            // Cargar relaciones
            $user->load('roles');

            // Crear token
            $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Usuario registrado correctamente',
                'user' => $user,
                'token' => $token
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar usuario',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * REGISTRO DE EMPLEADOS (para dueños registrando personal)
     */
    public function registerEmpleado(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'username' => 'required|string|max:50|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'propietario_id' => 'required|exists:propietarios,id',
            'rol_id' => 'required|exists:roles,id',
            'restaurante_id' => 'required|exists:restaurantes,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            // Crear usuario empleado
            $user = User::create([
                'propietario_id' => $request->propietario_id,
                'name' => $request->name,
                'email' => $request->email,
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'restaurante_activo' => $request->restaurante_id
            ]);

            // Asignar rol
            $user->roles()->attach($request->rol_id);
            
            // Asignar restaurante
            $user->restaurantes()->attach($request->restaurante_id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empleado registrado correctamente',
                'user' => $user->load('roles')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al registrar empleado',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Logout (revocar token)
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente'
        ]);
    }

    /**
     * Obtener usuario autenticado
     */
    public function me(Request $request)
    {
        $user = $request->user()->load(['roles', 'restauranteActivo']);
        
        return response()->json([
            'success' => true,
            'data' => $user
        ]);
    }

    /**
     * Cambiar restaurante activo
     */
    public function cambiarRestaurante(Request $request)
    {
        $request->validate([
            'restaurante_id' => 'required|exists:restaurantes,id'
        ]);

        $user = $request->user();

        // Verificar que el usuario pertenece a ese restaurante
        if (!$user->restaurantes()->where('restaurante_id', $request->restaurante_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a ese restaurante'
            ], 403);
        }

        $user->update([
            'restaurante_activo' => $request->restaurante_id
        ]);

        // Recargar el usuario para obtener los cambios
        $user = $user->fresh('restauranteActivo');

        return response()->json([
            'success' => true,
            'message' => 'Restaurante activo actualizado',
            'restaurante_activo' => $user->restauranteActivo
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RECUPERACIÓN DE CONTRASEÑA
    |--------------------------------------------------------------------------
    */

    /**
     * Enviar correo de restablecimiento de contraseña
     */
    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email'
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Hemos enviado un enlace de recuperación a tu correo electrónico'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo enviar el enlace de recuperación'
        ], 400);
    }

    /**
     * Restablecer contraseña
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed'
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60)
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Contraseña restablecida correctamente'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El token de recuperación es inválido o ha expirado'
        ], 400);
    }

    /**
     * Verificar token de restablecimiento
     */
    public function verifyResetToken(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email'
        ]);

        $user = User::where('email', $request->email)->first();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado'
            ], 404);
        }

        $broker = Password::broker();
        $result = $broker->tokenExists($user, $request->token);

        if ($result) {
            return response()->json([
                'success' => true,
                'message' => 'Token válido'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Token inválido o expirado'
        ], 400);
    }

    /**
     * Cambiar contraseña (estando autenticado)
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed|different:current_password'
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta'
            ], 403);
        }

        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña cambiada correctamente'
        ]);
    }
}