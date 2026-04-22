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
        'password'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'password' => 'hashed',
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

    /*
    |--------------------------------------------------------------------------
    | MÉTODOS DE ROLES Y PERMISOS
    |--------------------------------------------------------------------------
    */

    /**
     * Verificar si el usuario tiene un rol específico (case-insensitive)
     */
    public function hasRole(string $roleName): bool
    {
        return $this->roles()
            ->whereRaw('LOWER(nombre) = ?', [strtolower($roleName)])
            ->exists();
    }

    /**
     * Verificar si el usuario tiene un permiso específico
     */
    public function hasPermission($permission): bool
    {
        // Excepción automática para el rol Kiosko (MENU) 
        // Si no existen en la BD, se los otorgamos por código
        if ($this->hasRole('MENU')) {
            $menuPermisos = ['VER_RESTAURANTE', 'VER_PRODUCTOS', 'CREAR_ORDENES', 'VER_CATEGORIAS'];
            if (in_array($permission, $menuPermisos)) {
                return true;
            }
        }

        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permission) {
                $query->where('nombre', $permission);
            })
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | UTILIDADES
    |--------------------------------------------------------------------------
    */

    public function logAction($accion, $tabla, $registroId = null, $descripcion = null)
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