<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Propietario;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | AUTENTICACIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Login general — acepta email o username.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login'    => 'required|string',
            'password' => 'required|string',
        ]);

        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user  = User::where($field, $request->login)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        $user->load(['roles', 'restauranteActivo']);
        $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    /**
     * Login de empleados — usa la concatenación id-propietarioId-restauranteId.
     *
     * Ejemplo: "3-1-2" (id=3, propietario=1, restaurante=2)
     */
    public function loginEmpleado(Request $request): JsonResponse
    {
        $request->validate([
            'login'    => ['required', 'string', 'regex:/^\d+-\d+-\d+$/'],
            'password' => 'required|string',
        ], [
            'login.regex' => 'El formato debe ser id-propietarioId-restauranteId (ej: 3-1-2)',
        ]);

        [$userId, $propietarioId, $restauranteId] = explode('-', $request->login);

        $user = User::where('id', $userId)
            ->where('propietario_id', $propietarioId)
            ->where('restaurante_activo', $restauranteId)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Credenciales incorrectas',
            ], 401);
        }

        $user->load(['roles', 'restauranteActivo']);
        $token = $user->createToken('empleado_' . $user->id)->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login exitoso',
            'user'    => $user,
            'token'   => $token,
        ]);
    }

    /**
     * Logout — revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente',
        ]);
    }

    /**
     * Devuelve el usuario autenticado con sus relaciones.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $request->user()->load(['roles', 'restauranteActivo']),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRO
    |--------------------------------------------------------------------------
    */

    /**
     * Registro de clientes.
     *
     * Ruta pública. Se crea un User con rol CLIENTE (id=6), sin propietario
     * ni restaurante asignado — puede explorar todos los menús.
     */
    public function registerCliente(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre'   => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'telefono' => 'nullable|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ], [
            'email.unique'       => 'Ya existe una cuenta con ese correo.',
            'password.min'       => 'La contraseña debe tener al menos 6 caracteres.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
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
            $user = User::create([
                'name'     => $request->nombre,
                'email'    => $request->email,
                'username' => $this->generateUsername($request->email),
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
            ]);

            $rolCliente = Role::whereRaw('LOWER(nombre) = ?', ['cliente'])->first()
                ?? Role::find(6);

            if (! $rolCliente) {
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
     * Registro de propietarios/dueños.
     *
     * Crea automáticamente un Propietario asociado al nuevo usuario.
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users',
            'username' => 'required|string|max:50|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'rol_id'   => 'required|exists:roles,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            $propietario = Propietario::create([
                'nombre' => $request->name,
                'email'  => $request->email,
            ]);

            $user = User::create([
                'propietario_id' => $propietario->id,
                'name'           => $request->name,
                'email'          => $request->email,
                'username'       => $request->username,
                'password'       => Hash::make($request->password),
            ]);

            $user->roles()->attach($request->rol_id);

            DB::commit();

            $user->load('roles');
            $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

            return response()->json([
                'success' => true,
                'message' => 'Usuario registrado correctamente',
                'user'    => $user,
                'token'   => $token,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar usuario',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Registro de empleados.
     *
     * Solo accesible para dueños. Requiere propietario y restaurante existentes.
     */
    public function registerEmpleado(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'           => 'required|string|max:255',
            'email'          => 'nullable|email|max:255|unique:users',
            'username'       => 'nullable|string|max:50|unique:users',
            'password'       => 'required|string|min:8|confirmed',
            'propietario_id' => 'required|exists:propietarios,id',
            'rol_id'         => 'required|exists:roles,id',
            'restaurante_id' => 'required|exists:restaurantes,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();

        try {
            // email y username son opcionales: si no se envían se generan
            // internamente para cumplir la restricción unique de la tabla.
            $email    = $request->email
                ?? 'emp_' . $request->propietario_id . '_' . Str::random(8) . '@sin-correo.local';

            $username = $request->username
                ?? $this->generateUsername(Str::slug($request->name));

            $user = User::create([
                'propietario_id'     => $request->propietario_id,
                'name'               => $request->name,
                'email'              => $email,
                'username'           => $username,
                'password'           => Hash::make($request->password),
                'restaurante_activo' => $request->restaurante_id,
            ]);

            $user->roles()->attach($request->rol_id);
            $user->restaurantes()->attach($request->restaurante_id);

            DB::commit();

            $cadenaAcceso = "{$user->id}-{$user->propietario_id}-{$user->restaurante_activo}";

            return response()->json([
                'success'        => true,
                'message'        => 'Empleado registrado correctamente',
                'user'           => $user->load('roles'),
                'login_empleado' => $cadenaAcceso,  // ej: "3-1-2" → compartir al empleado
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error al registrar empleado',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | GESTIÓN DE RESTAURANTE ACTIVO
    |--------------------------------------------------------------------------
    */

    /**
     * Cambia el restaurante activo del usuario autenticado.
     */
    public function cambiarRestaurante(Request $request): JsonResponse
    {
        $request->validate([
            'restaurante_id' => 'required|exists:restaurantes,id',
        ]);

        $user = $request->user();

        if (! $user->restaurantes()->where('restaurante_id', $request->restaurante_id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes acceso a ese restaurante',
            ], 403);
        }

        $user->update(['restaurante_activo' => $request->restaurante_id]);

        return response()->json([
            'success'            => true,
            'message'            => 'Restaurante activo actualizado',
            'restaurante_activo' => $user->fresh('restauranteActivo')->restauranteActivo,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RECUPERACIÓN DE CONTRASEÑA
    |--------------------------------------------------------------------------
    */

    /**
     * Envía un enlace de restablecimiento al correo indicado.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
        ]);

        $status = Password::sendResetLink($request->only('email'));

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Hemos enviado un enlace de recuperación a tu correo electrónico',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No se pudo enviar el enlace de recuperación',
        ], 400);
    }

    /**
     * Restablece la contraseña usando el token recibido por correo.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Contraseña restablecida correctamente',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'El token de recuperación es inválido o ha expirado',
        ], 400);
    }

    /**
     * Verifica si un token de restablecimiento sigue siendo válido.
     */
    public function verifyResetToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Usuario no encontrado',
            ], 404);
        }

        $valid = Password::broker()->tokenExists($user, $request->token);

        return $valid
            ? response()->json(['success' => true,  'message' => 'Token válido'])
            : response()->json(['success' => false, 'message' => 'Token inválido o expirado'], 400);
    }

    /**
     * Cambia la contraseña del usuario autenticado.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required',
            'new_password'     => 'required|min:8|confirmed|different:current_password',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'La contraseña actual es incorrecta',
            ], 403);
        }

        $user->update(['password' => Hash::make($request->new_password)]);

        return response()->json([
            'success' => true,
            'message' => 'Contraseña cambiada correctamente',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS PRIVADOS
    |--------------------------------------------------------------------------
    */

    /**
     * Genera un username único a partir de la parte local del email.
     */
    private function generateUsername(string $email): string
    {
        $base = strtolower(explode('@', $email)[0]);
        $username = $base;
        $i = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . $i++;
        }

        return $username;
    }
}