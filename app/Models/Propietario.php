<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class Propietario extends Model
{
    use SoftDeletes;
    

    protected $table = 'propietarios';

    protected $fillable = [
        'nombre',
        'apellido',
        'correo',
        'telefono',
        'rfc',
        'regimen_fiscal'
    ];

    public function restaurantes()
    {
        return $this->hasMany(Restaurante::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function licencias()
    {
        return $this->hasMany(PropietarioLicencia::class);
    }

    /**
     * 👇 VERIFICAR SI TIENE UNA LICENCIA ACTIVA
     */
    public function licenciaActiva()
    {
        return $this->licencias()
            ->where('estado', 'activa')
            ->where('fecha_expiracion', '>', now())
            ->exists();
    }

    /**
     * Obtener la licencia activa actual
     */
    public function getLicenciaActiva()
    {
        return $this->licencias()
            ->with('licencia')
            ->where('estado', 'activa')
            ->where('fecha_expiracion', '>', now())
            ->first();
    }

    /**
     * Verificar si puede crear otro restaurante
     */
    public function puedeCrearRestaurante()
    {
        $licenciaActiva = $this->getLicenciaActiva();
        
        if (!$licenciaActiva) {
            return false;
        }

        $maxRestaurantes = $licenciaActiva->licencia->max_restaurantes ?? 1;
        $restaurantesActuales = $this->restaurantes()->count();

        return $restaurantesActuales < $maxRestaurantes;
    }

    /**
     * Verificar si la licencia activa del propietario tiene un permiso específico
     */
    public function licenciaTienePermiso(string $permiso): bool
    {
        $licenciaActiva = $this->getLicenciaActiva();

        if (!$licenciaActiva || !$licenciaActiva->licencia) {
            return false;
        }

        return $licenciaActiva->licencia->tienePermiso($permiso);
    }
}