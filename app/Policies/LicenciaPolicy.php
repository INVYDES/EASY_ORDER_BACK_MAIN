<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Licencia;

class LicenciaPolicy
{
    /**
     * Solo propietarios y admins pueden gestionar licencias
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($user->hasPermission('ADMIN_LICENCIAS')) {
            return true;
        }
        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('VER_LICENCIAS');
    }

    public function view(User $user, Licencia $licencia): bool
    {
        return $user->hasPermission('VER_LICENCIAS');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('CREAR_LICENCIAS');
    }

    public function update(User $user, Licencia $licencia): bool
    {
        return $user->hasPermission('EDITAR_LICENCIAS');
    }

    public function delete(User $user, Licencia $licencia): bool
    {
        return $user->hasPermission('ELIMINAR_LICENCIAS');
    }
}