<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Role;
use App\Models\Propietario;
use App\Models\Restaurante;
use App\Models\Log;


class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes;

    protected $table = 'users';

    protected $fillable = [
        'propietario_id',
        'restaurante_activo',
        'name',
        'email',
        'username',
        'password',
        'activo',
        'telefono',            // FIX: Agregado — faltaba y se ignoraba silenciosamente en registerCliente
        'tipo_empleado',
        'salario_base',
        'salario_por_hora',
        'comision_por_venta',
        'fecha_contratacion',
        'en_linea'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password'           => 'hashed',
        'activo'             => 'boolean',
        'salario_base'       => 'decimal:2',
        'salario_por_hora'   => 'decimal:2',
        'comision_por_venta' => 'decimal:2',
        'en_linea'           => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | RELACIONES
    |--------------------------------------------------------------------------
    */

    public function propietario()
    {
        return $this->belongsTo(Propietario::class);
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function restauranteActivo()
    {
        return $this->belongsTo(Restaurante::class, 'restaurante_activo');
    }

    public function restaurantes()
    {
        return $this->belongsToMany(Restaurante::class, 'restaurante_user')
                    ->withTimestamps();
    }

    public function restaurantesDelPropietario()
    {
        return $this->hasMany(Restaurante::class, 'propietario_id', 'propietario_id');
    }

    public function asistencias()
    {
        return $this->hasMany(Asistencia::class);
    }

    public function nominas()
    {
        return $this->hasMany(Nomina::class);
    }

    public function horarios()
    {
        return $this->hasMany(HorarioEmpleado::class, 'user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | MÉTODOS DE ROLES Y PERMISOS
    |--------------------------------------------------------------------------
    */

    /**
     * Verificar si el usuario tiene un rol específico (case-insensitive).
     * FIX: Aprovecha la colección ya cargada si existe, evitando query extra.
     */
    public function hasRole(string $roleName): bool
    {
        // Si la relación ya fue cargada (eager load), usar la colección en memoria
        if ($this->relationLoaded('roles')) {
            return $this->roles->contains(
                fn($r) => strtolower($r->nombre) === strtolower($roleName)
            );
        }

        // Si no está cargada, hacer la query
        return $this->roles()
            ->whereRaw('LOWER(nombre) = ?', [strtolower($roleName)])
            ->exists();
    }

    /**
     * Verificar si el usuario tiene un permiso específico.
     *
     * FIX CRÍTICO: Antes hacía N+1 queries (una por hasRole + una por whereHas por cada permiso).
     * Ahora carga roles+permisos una sola vez con loadMissing y opera en memoria.
     */
    public function hasPermission(string $permission): bool
    {
        // Cargar roles con sus permisos una sola vez (no recarga si ya están en memoria)
        $this->loadMissing('roles.permissions');

        // Bypas total para PROPIETARIO o DUEÑO
        if ($this->hasRole('PROPIETARIO') || $this->hasRole('DUEÑO') || $this->hasRole('SUPER_ADMIN')) {
            return true;
        }

        // FIX: Usar la colección ya cargada en lugar de llamar hasRole() que haría otra query
        $esMenu = $this->roles->contains(
            fn($r) => strtolower($r->nombre) === 'menu'
        );

        // Excepción automática para el rol Kiosko (MENU)
        if ($esMenu) {
            $menuPermisos = ['VER_RESTAURANTE', 'VER_PRODUCTOS', 'CREAR_ORDENES', 'VER_CATEGORIAS'];
            if (in_array($permission, $menuPermisos)) {
                return true;
            }
        }

        // Buscar el permiso en todos los roles del usuario (operación en memoria, sin queries)
        return $this->roles
            ->flatMap(fn($role) => $role->permissions)
            ->contains('nombre', $permission);
    }

    /*
    |--------------------------------------------------------------------------
    | SCOPES
    |--------------------------------------------------------------------------
    */

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeInactivos($query)
    {
        return $query->where('activo', false);
    }

    /*
    |--------------------------------------------------------------------------
    | UTILIDADES
    |--------------------------------------------------------------------------
    */

    /**
     * Registra una acción de auditoría del usuario.
     */
    public function logAction(string $accion, string $tabla, $registroId = null, ?string $descripcion = null): Log
    {
        return Log::create([
            'user_id'        => $this->id,
            'accion'         => $accion,
            'tabla_afectada' => $tabla,
            'registro_id'    => $registroId,
            'descripcion'    => $descripcion,
            'ip_address'     => request()->ip(),
        ]);
    }
}