<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Categoria;

class CategoriaPolicy
{
    private function mismoRestaurante(User $user, Categoria $categoria): bool
    {
        $restauranteActivo = app('restaurante_activo');
        
        return $categoria->restaurante_id === $restauranteActivo->id;
    }

    public function view(User $user, Categoria $categoria): bool
    {
        return $this->mismoRestaurante($user, $categoria);
    }

    public function update(User $user, Categoria $categoria): bool
    {
        return $this->mismoRestaurante($user, $categoria);
    }

    public function delete(User $user, Categoria $categoria): bool
    {
        return $this->mismoRestaurante($user, $categoria);
    }
}