<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Orden;

class OrdenPolicy
{
    private function mismoRestaurante(User $user, Orden $orden): bool
    {
        $restauranteActivo = app('restaurante_activo');
        
        return $orden->restaurante_id === $restauranteActivo->id;
    }

    public function view(User $user, Orden $orden): bool
    {
        return $this->mismoRestaurante($user, $orden);
    }

    public function update(User $user, Orden $orden): bool
    {
        return $this->mismoRestaurante($user, $orden);
    }

    public function delete(User $user, Orden $orden): bool
    {
        return $this->mismoRestaurante($user, $orden);
    }
}