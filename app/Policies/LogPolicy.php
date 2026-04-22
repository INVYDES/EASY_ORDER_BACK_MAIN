<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Log;

class LogPolicy
{
    /**
     * Verificar si el log pertenece al propietario del usuario
     */
    public function mismoPropietario(User $user, Log $log): bool
    {
        return $log->user && $log->user->propietario_id === $user->propietario_id;
    }

    public function view(User $user, Log $log): bool
    {
        // Propietario puede ver todos sus logs
        if ($user->hasPermission('VER_LOGS') && $this->mismoPropietario($user, $log)) {
            return true;
        }
        
        // Usuario normal solo sus propios logs
        return $user->hasPermission('VER_LOGS') && $log->user_id === $user->id;
    }

    public function delete(User $user, Log $log): bool
    {
        return $user->hasPermission('ELIMINAR_LOGS') && $this->mismoPropietario($user, $log);
    }
}