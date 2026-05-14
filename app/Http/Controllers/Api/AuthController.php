<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\LoginEmpleadoRequest;
use App\Http\Resources\UserResource;
use App\Models\Categoria;
use App\Models\Propietario;
use App\Models\PropietarioLicencia;
use App\Models\Restaurante;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use App\Http\Controllers\Controller;

final class AuthController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | AUTENTICACIÓN
    |--------------------------------------------------------------------------
    */

    /**
     * Login general — acepta email o username.
     * FIX: Verifica licencia activa del propietario antes de permitir el acceso.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $field = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';

        // Buscamos al usuario sin filtrar por 'activo' para permitir que el login lo reactive
        $user = User::where($field, $request->login)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->failure('Credenciales incorrectas', 401);
        }

        $licenciaError = $this->verificarLicencia($user);
        if ($licenciaError !== null) {
            return $licenciaError;
        }

        $this->ensureRestauranteActivo($user);
        $this->ensureCategoriasBase($user);

        $user->load(['roles', 'restauranteActivo']);

        $user->tokens()->delete();

        // Al loguearse, marcamos como Activo y En Línea
        $user->update([
            'activo'   => true,
            'en_linea' => true
        ]);

        $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

        return $this->success([
            'user'  => new UserResource($user),
            'token' => $token,
        ]);
    }

    public function loginEmpleado(LoginEmpleadoRequest $request): JsonResponse
    {
        [$userId, $propietarioId, $restauranteId] = explode('-', $request->login);

        $user = User::where('id', (int) $userId)
            ->where('propietario_id', (int) $propietarioId)
            ->where('restaurante_activo', (int) $restauranteId)
            ->where('activo', true)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return $this->failure('Credenciales incorrectas o cuenta desactivada', 401);
        }

        $licenciaError = $this->verificarLicencia($user);
        if ($licenciaError !== null) {
            return $licenciaError;
        }

        $user->load(['roles', 'restauranteActivo']);

        $user->tokens()->delete();

        // Marcar como en línea
        $user->update(['en_linea' => true]);

        $token = $user->createToken('empleado_' . $user->id)->plainTextToken;

        return $this->success([
            'user'  => new UserResource($user),
            'token' => $token,
        ]);
    }

    /**
     * Logout — revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        
        // Marcar como fuera de línea
        $user->update(['en_linea' => false]);

        $user->currentAccessToken()->delete();

        return $this->success(null, message: 'Sesión cerrada correctamente');
    }

    /**
     * Refresh Token — revoca el token actual y emite uno nuevo.
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->currentAccessToken()->delete();

        $token = $user->createToken('api_token_' . $user->id)->plainTextToken;

        return $this->success(['token' => $token], message: 'Token renovado correctamente');
    }

    /**
     * Devuelve el usuario autenticado con sus relaciones.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->ensureRestauranteActivo($user);
        $this->ensureCategoriasBase($user);

        return $this->success(
            new UserResource($user->load(['roles', 'restauranteActivo'])),
        );
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
        $request->validate([
            'nombre'   => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $result = DB::transaction(function () use ($request): array {
            $user = User::create([
                'name'     => $request->nombre,
                'email'    => $request->email,
                'username' => $this->generateUniqueUsername(
                    explode('@', $request->email)[0]
                ),
                'password' => Hash::make($request->password),
                'telefono' => $request->telefono,
            ]);

            $user->roles()->attach(6);

            return [
                'user'  => $user,
                'token' => $user->createToken('cliente')->plainTextToken,
            ];
        });

        return $this->success($result, status: 201);
    }

    /**
     * Registro de propietarios/dueños.
     */
    public function register(Request $request): JsonResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'username' => ['required', 'string', 'max:50', 'unique:users,username', 'alpha_dash'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
            'rol_id'   => ['required', 'integer', 'exists:roles,id'],
        ]);

        $result = DB::transaction(function () use ($request): array {
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

            $user->roles()->attach((int) $request->rol_id);

            return [
                'user'  => $user,
                'token' => $user->createToken('admin')->plainTextToken,
            ];
        });

        return $this->success($result, status: 201);
    }

    /**
     * Registro de empleados.
     */
    public function registerEmpleado(Request $request): JsonResponse
    {
        $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'password'       => ['required', 'confirmed', PasswordRule::min(8)],
            'propietario_id' => ['required', 'integer', 'exists:propietarios,id'],
            'rol_id'         => ['required', 'integer', 'exists:roles,id'],
            'restaurante_id' => ['required', 'integer', 'exists:restaurantes,id'],
        ]);

        $result = DB::transaction(function () use ($request): array {
            $email = 'emp_' . $request->propietario_id . '_' . Str::random(8) . '@sin-correo.local';

            $user = User::create([
                'propietario_id'     => $request->propietario_id,
                'name'               => $request->name,
                'email'              => $email,
                'username'           => 'tmp_' . Str::random(10),
                'password'           => Hash::make($request->password),
                'restaurante_activo' => $request->restaurante_id,
            ]);

            $user->update(['username' => "{$request->propietario_id}{$user->id}"]);

            $user->roles()->attach((int) $request->rol_id);
            $user->restaurantes()->attach((int) $request->restaurante_id);

            $cadenaAcceso = "{$user->id}-{$request->propietario_id}-{$request->restaurante_id}";

            return [
                'user'           => $user->load('roles'),
                'login_empleado' => $cadenaAcceso,
            ];
        });

        return $this->success($result, status: 201, message: 'Empleado registrado correctamente');
    }

    /*
    |--------------------------------------------------------------------------
    | CUENTA
    |--------------------------------------------------------------------------
    */

    /**
     * Cambia el restaurante activo (solo ADMIN o DUEÑO).
     */
    public function cambiarRestaurante(Request $request): JsonResponse
    {
        $request->validate([
            'restaurante_id' => ['required', 'integer', 'exists:restaurantes,id'],
        ]);

        $user = $request->user();

        if (! $user->hasRole('ADMIN') && ! $user->hasRole('DUEÑO')) {
            return $this->failure('No tienes permisos de administrador para cambiar de sucursal', 403);
        }

        $pertenece = DB::table('restaurantes')
            ->where('id', $request->restaurante_id)
            ->where('propietario_id', $user->propietario_id)
            ->exists();

        if (! $pertenece) {
            return $this->failure('No tienes permiso para acceder a esta sucursal', 403);
        }

        $user->update(['restaurante_activo' => $request->restaurante_id]);

        Cache::forget("user_first_res_{$user->id}");

        return $this->success(
            ['restaurante_id' => $user->restaurante_activo],
            message: 'Sucursal actualizada correctamente',
        );
    }

    /**
     * Cambia la contraseña y revoca TODAS las sesiones activas.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return $this->failure('La contraseña actual es incorrecta', 422);
        }

        DB::transaction(function () use ($user, $request): void {
            $user->update(['password' => Hash::make($request->password)]);
            $user->tokens()->delete();
            Cache::forget("user_first_res_{$user->id}");
            Cache::forget("cats_base_{$user->restaurante_activo}");
        });

        return $this->success(
            null,
            message: 'Contraseña actualizada. Todas las sesiones han sido cerradas por seguridad.',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | RECUPERACIÓN DE CONTRASEÑA
    | Requiere: MAIL_* configurado en .env y tabla password_reset_tokens
    |--------------------------------------------------------------------------
    */

    /**
     * Envía el email de recuperación de contraseña.
     *
     * Laravel genera un token seguro, lo guarda en password_reset_tokens
     * y envía el email con el link al frontend.
     *
     * .env requerido:
     *   MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD
     *   MAIL_FROM_ADDRESS, MAIL_FROM_NAME
     *   FRONTEND_URL (para construir el link de reset)
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        // Password::sendResetLink busca el usuario por email, genera el token
        // y dispara el evento que envía el correo (Notification: ResetPassword)
        $status = Password::sendResetLink(
            $request->only('email')
        );

        // Siempre devolver 200 aunque el email no exista — evita email enumeration
        // El mensaje es genérico intencionalmente
        return $this->success(
            null,
            message: 'Si existe una cuenta con ese correo, recibirás un enlace para restablecer tu contraseña.',
        );
    }

    /**
     * Verifica que el token de reset sea válido antes de mostrar el formulario.
     * El frontend puede llamar esto para validar el token al cargar la página de reset.
     */
    public function verifyResetToken(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
        ]);

        // Buscar el registro en password_reset_tokens
        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (! $record) {
            return $this->failure('Token inválido o expirado', 422);
        }

        // Verificar que el token coincide (está hasheado en la BD)
        if (! Hash::check($request->token, $record->token)) {
            return $this->failure('Token inválido o expirado', 422);
        }

        // Verificar que no haya expirado (por defecto 60 minutos en config/auth.php)
        $expiredAt = now()->subMinutes(config('auth.passwords.users.expire', 60));
        if ($record->created_at < $expiredAt) {
            return $this->failure('El enlace de recuperación ha expirado. Solicita uno nuevo.', 422);
        }

        return $this->success(null, message: 'Token válido');
    }

    /**
     * Restablece la contraseña usando el token enviado por email.
     *
     * El frontend debe enviar: email, token (de la URL), password, password_confirmation
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)
                ->mixedCase()   // mayúsculas y minúsculas
                ->numbers(),    // al menos un número
            ],
        ]);

        // Password::reset valida el token, cambia la contraseña y elimina el token usado
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Revocar TODOS los tokens activos por seguridad
                $user->tokens()->delete();

                // Limpiar caché del tenant
                Cache::forget("user_first_res_{$user->id}");
                Cache::forget("cats_base_{$user->restaurante_activo}");

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->success(
                null,
                message: 'Contraseña restablecida correctamente. Puedes iniciar sesión.',
            );
        }

        // Mapear errores de Laravel a mensajes legibles
        $mensaje = match ($status) {
            Password::INVALID_TOKEN => 'El enlace de recuperación es inválido o ya fue usado.',
            Password::INVALID_USER  => 'No existe una cuenta con ese correo electrónico.',
            Password::RESET_THROTTLED => 'Demasiados intentos. Espera unos minutos antes de intentar de nuevo.',
            default                 => 'No se pudo restablecer la contraseña. Intenta de nuevo.',
        };

        return $this->failure($mensaje, 422);
    }

    /*
    |--------------------------------------------------------------------------
    | HELPERS PRIVADOS
    |--------------------------------------------------------------------------
    */

    /**
     * Verifica que el propietario del usuario tenga una licencia activa.
     * Los empleados heredan la licencia del propietario al que pertenecen.
     * Los clientes (rol 6) y usuarios sin propietario_id no requieren licencia.
     *
     * Devuelve JsonResponse de error si no tiene licencia, null si está OK.
     */
    private function verificarLicencia(User $user): ?JsonResponse
    {
        // Solo verificar para usuarios con propietario_id (dueños y empleados)
        if (! $user->propietario_id) {
            return null;
        }

$tieneLicencia = PropietarioLicencia::where('propietario_id', $user->propietario_id)
    ->where('estado', 'ACTIVA')
    ->where('fecha_expiracion', '>', now())
    ->exists();

if (! $tieneLicencia) {

       
            // Verificar si tiene licencia pero vencida — mensaje más específico
            $licenciaVencida = PropietarioLicencia::where('propietario_id', $user->propietario_id)
                ->where('estado', 'ACTIVA')
                ->where('fecha_expiracion', '<=', now())
                ->exists();

            $mensaje = $licenciaVencida
                ? 'Tu licencia ha vencido. Renueva tu suscripción para continuar.'
                : 'No tienes una licencia activa. Adquiere un plan para acceder al sistema.';

            return $this->failure($mensaje, 402); // 402 Payment Required
        }

        return null;
    }

    /**
     * Garantiza que el usuario propietario tenga siempre un restaurante activo asignado.
     */
    private function ensureRestauranteActivo(User $user): void
    {
        if (! $user->propietario_id || $user->restaurante_activo) {
            return;
        }

        $primer = Restaurante::where('propietario_id', $user->propietario_id)->first();

        if ($primer) {
            $user->restaurante_activo = $primer->id;
            $user->saveQuietly();
        }
    }

    /**
     * Auto-sanación de categorías base con caché de 24h.
     * Usa una sola query + insert masivo en lugar de N+1 individuales.
     */
    private function ensureCategoriasBase(User $user): void
    {
        if (! $user->restaurante_activo) {
            return;
        }

        Cache::remember("cats_base_{$user->restaurante_activo}", 86400, function () use ($user): bool {
            $base = [
                ['nombre' => 'Cocina',  'color' => '#10B981'],
                ['nombre' => 'Barra',   'color' => '#6366F1'],
                ['nombre' => 'Postres', 'color' => '#EC4899'],
            ];

            $existentes = Categoria::where('restaurante_id', $user->restaurante_activo)
                ->whereIn('nombre', array_column($base, 'nombre'))
                ->pluck('nombre')
                ->toArray();

            $porInsertar = array_values(array_filter(
                $base,
                fn (array $cat) => ! in_array($cat['nombre'], $existentes, true),
            ));

            if (! empty($porInsertar)) {
                $now = now();
                Categoria::insert(array_map(
                    fn (array $cat) => [
                        'restaurante_id' => $user->restaurante_activo,
                        'nombre'         => $cat['nombre'],
                        'color'          => $cat['color'],
                        'activo'         => true,
                        'orden'          => 0,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ],
                    $porInsertar,
                ));
            }

            return true;
        });
    }

    /**
     * Genera un username único a partir de una cadena base.
     */
    private function generateUniqueUsername(string $base): string
    {
        $username = preg_replace('/[^a-z0-9]/', '', strtolower($base)) ?? 'usuario';
        $original = $username ?: 'usuario';
        $i        = 1;

        while (User::where('username', $username)->exists()) {
            $username = $original . $i++;
        }

        return $username;
    }

    /**
     * Envelope de respuesta exitosa estándar.
     */
    private function success(
        mixed $data,
        array $meta = [],
        int $status = 200,
        ?string $message = null,
    ): JsonResponse {
        $body = [
            'success' => true,
            'data'    => $data,
            'error'   => null,
            'meta'    => $meta,
        ];

        if ($message !== null) {
            $body['message'] = $message;
        }

        return response()->json($body, $status);
    }

    /**
     * Envelope de respuesta de error estándar.
     */
    private function failure(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'error'   => $message,
            'meta'    => [],
        ], $status);
    }
}