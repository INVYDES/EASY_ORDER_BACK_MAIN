<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Cliente;

class ClientePolicy
{
    private function mismoRestaurante(User $user, Cliente $cliente): bool
    {
        $restauranteActivo = app('restaurante_activo');
        
        return $cliente->restaurante_id === $restauranteActivo->id;
    }

    public function view(User $user, Cliente $cliente): bool
    {
        return $this->mismoRestaurante($user, $cliente);
    }

    public function update(User $user, Cliente $cliente): bool
    {
        return $this->mismoRestaurante($user, $cliente);
    }

    public function delete(User $user, Cliente $cliente): bool
    {
        return $this->mismoRestaurante($user, $cliente);
    }
}