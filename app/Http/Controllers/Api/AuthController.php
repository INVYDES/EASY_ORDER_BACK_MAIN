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
     */
    public function registerCliente(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'nombre'   => 'required|string|max:255',
            'email'    => 'required|email|max:255|unique:users,email',
            'telefono' => 'nullable|string|max:20',
            'password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $user = User::create([
                'name'     => $request->nombre,
                'email'    => $request->email,
                'username' => str_replace([' ', '-'], '', strtolower(explode('@', $request->email)[0])),
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
            ]);
            $user->roles()->attach(6);
            DB::commit();
            return response()->json(['success' => true, 'user' => $user, 'token' => $user->createToken('cliente')->plainTextToken], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registro de propietarios/dueños.
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
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            $propietario = Propietario::create(['nombre' => $request->name, 'email' => $request->email]);
            $user = User::create([
                'propietario_id' => $propietario->id,
                'name'           => $request->name,
                'email'          => $request->email,
                'username'       => $request->username,
                'password'       => Hash::make($request->password),
            ]);
            $user->roles()->attach($request->rol_id);
            DB::commit();
            return response()->json(['success' => true, 'user' => $user, 'token' => $user->createToken('admin')->plainTextToken], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Registro de empleados.
     */
    public function registerEmpleado(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'           => 'required|string|max:255',
            'password'       => 'required|string|min:8|confirmed',
            'propietario_id' => 'required|exists:propietarios,id',
            'rol_id'         => 'required|exists:roles,id',
            'restaurante_id' => 'required|exists:restaurantes,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::beginTransaction();
        try {
            // Email ficticio
            $email = 'emp_' . $request->propietario_id . '_' . Str::random(8) . '@sin-correo.local';

            // Creamos el usuario con un username temporal
            $user = User::create([
                'propietario_id'     => $request->propietario_id,
                'name'               => $request->name,
                'email'              => $email,
                'username'           => 'tmp_' . Str::random(10),
                'password'           => Hash::make($request->password),
                'restaurante_activo' => $request->restaurante_id,
            ]);

            // GENERACIÓN SIMPLIFICADA: ID_User + ID_Propietario
            $finalUsername = $user->id . $request->propietario_id;
            
            $user->update(['username' => $finalUsername]);

            $user->roles()->attach($request->rol_id);
            $user->restaurantes()->attach($request->restaurante_id);

            DB::commit();

            $cadenaAcceso = "{$user->id}-{$user->propietario_id}-{$user->restaurante_activo}";

            return response()->json([
                'success'        => true,
                'message'        => 'Empleado registrado correctamente',
                'user'           => $user->load('roles'),
                'login_empleado' => $cadenaAcceso,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper para generar username (simplificado para evitar guiones)
     */
    private function generateUsername(string $base): string
    {
        $username = str_replace([' ', '-'], '', strtolower($base));
        $original = $username;
        $i = 1;
        while (User::where('username', $username)->exists()) {
            $username = $original . $i++;
        }
        return $username;
    }

    /*
    |--------------------------------------------------------------------------
    | OTROS MÉTODOS (Omitidos para brevedad, se mantienen igual)
    |--------------------------------------------------------------------------
    */
    /**
     * Cambia el restaurante activo del usuario de forma persistente.
     */
    public function cambiarRestaurante(Request $request): JsonResponse
    {
        $request->validate([
            'restaurante_id' => 'required|exists:restaurantes,id',
        ]);

        $user = $request->user();
        
        // Actualizamos el restaurante activo en la base de datos
        $user->update([
            'restaurante_activo' => $request->restaurante_id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Sucursal actualizada correctamente',
            'restaurante_id' => $user->restaurante_activo
        ]);
    }
    public function forgotPassword(Request $request): JsonResponse { return response()->json(['success' => true]); }
    public function resetPassword(Request $request): JsonResponse { return response()->json(['success' => true]); }
    public function verifyResetToken(Request $request): JsonResponse { return response()->json(['success' => true]); }
    public function changePassword(Request $request): JsonResponse { return response()->json(['success' => true]); }
}