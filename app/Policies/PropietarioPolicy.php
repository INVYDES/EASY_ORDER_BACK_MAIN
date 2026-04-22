<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Propietario;

class PropietarioPolicy
{
    /**
     * Verificar si el usuario es el propietario
     */
    public function esPropietario(User $user, Propietario $propietario): bool
    {
        return $user->propietario_id === $propietario->id;
    }

    public function view(User $user, Propietario $propietario): bool
    {
        // Solo el mismo propietario o admin puede ver
        return $this->esPropietario($user, $propietario) || $user->hasPermission('VER_TODOS_PROPIETARIOS');
    }

    public function update(User $user, Propietario $propietario): bool
    {
        return $this->esPropietario($user, $propietario) || $user->hasPermission('EDITAR_TODOS_PROPIETARIOS');
    }

    public function delete(User $user, Propietario $propietario): bool
    {
        return $user->hasPermission('ELIMINAR_PROPIETARIOS') && $this->esPropietario($user, $propietario);
    }
}