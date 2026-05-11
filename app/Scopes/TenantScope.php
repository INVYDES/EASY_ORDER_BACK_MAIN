<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Aplicar el scope para filtrar por el restaurante activo.
     */
    public function apply(Builder $builder, Model $model)
    {
        $restauranteId = null;

        // 1. Intentar obtener de la instancia global (setted by CheckTenant middleware)
        if (app()->bound('restaurante_activo')) {
            $restaurante = app('restaurante_activo');
            $restauranteId = is_object($restaurante) ? $restaurante->id : $restaurante;
        }

        // 2. Si no hay instancia (ej: comandos artisan o jobs), intentar del usuario autenticado
        if (!$restauranteId && auth()->check()) {
            $restauranteId = auth()->user()->restaurante_activo;
        }

        if ($restauranteId) {
            $builder->where($model->getTable() . '.restaurante_id', $restauranteId);
        }
    }
}
