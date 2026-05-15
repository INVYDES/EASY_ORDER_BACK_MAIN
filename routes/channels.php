<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('restaurante.{id}', function ($user, $id) {
    return (int) $user->restaurante_activo === (int) $id
        || $user->restaurantes()->where('restaurantes.id', $id)->exists();
});
