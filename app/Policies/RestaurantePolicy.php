<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Restaurante;

class RestaurantePolicy
{
    /**
     * Verificar si el usuario pertenece al restaurante
     */
    public function pertenece(User $user, Restaurante $restaurante): bool
    {
        return $user->restaurantes()
            ->where('restaurantes.id', $restaurante->id)
            ->exists();
    }

    /**
     * Verificar si el restaurante pertenece al propietario del usuario
     */
    public function mismoPropietario(User $user, Restaurante $restaurante): bool
    {
        return $user->propietario_id === $restaurante->propietario_id;
    }

    public function view(User $user, Restaurante $restaurante): bool
    {
        return $this->pertenece($user, $restaurante);
    }

    public function update(User $user, Restaurante $restaurante): bool
    {
        return $this->pertenece($user, $restaurante) && $this->mismoPropietario($user, $restaurante);
    }

    public function delete(User $user, Restaurante $restaurante): bool
    {
        return $this->pertenece($user, $restaurante) && $this->mismoPropietario($user, $restaurante);
    }
}