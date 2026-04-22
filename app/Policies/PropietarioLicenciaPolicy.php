<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PropietarioLicencia;

class PropietarioLicenciaPolicy
{
    /**
     * Verificar si la asignación pertenece al propietario del usuario
     */
    public function mismoPropietario(User $user, PropietarioLicencia $asignacion): bool
    {
        return $user->propietario_id === $asignacion->propietario_id;
    }

    public function view(User $user, PropietarioLicencia $asignacion): bool
    {
        return $user->hasPermission('VER_PROPIETARIO_LICENCIA') && 
               $this->mismoPropietario($user, $asignacion);
    }

    public function update(User $user, PropietarioLicencia $asignacion): bool
    {
        return $user->hasPermission('EDITAR_PROPIETARIO_LICENCIA') && 
               $this->mismoPropietario($user, $asignacion);
    }

    public function delete(User $user, PropietarioLicencia $asignacion): bool
    {
        return $user->hasPermission('ELIMINAR_PROPIETARIO_LICENCIA') && 
               $this->mismoPropietario($user, $asignacion);
    }
}