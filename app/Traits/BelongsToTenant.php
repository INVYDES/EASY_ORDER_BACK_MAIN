<?php

namespace App\Traits;

use App\Scopes\TenantScope;

trait BelongsToTenant
{
    /**
     * El "booting" del trait aplica el scope global.
     */
    protected static function bootBelongsToTenant()
    {
        static::addGlobalScope(new TenantScope);

        // Al crear un nuevo registro, asignar automáticamente el restaurante_id
        static::creating(function ($model) {
            if (!$model->restaurante_id && app()->bound('restaurante_activo')) {
                $restaurante = app('restaurante_activo');
                $model->restaurante_id = is_object($restaurante) ? $restaurante->id : $restaurante;
            }
        });
    }
}
