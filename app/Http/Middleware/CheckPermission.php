<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function handle(Request $request, Closure $next, ...$permissions)
{
    $user = $request->user();

    if (!$user) {
        return response()->json([
            'success' => false,
            'message' => 'No autenticado'
        ], 401);
    }

    foreach ($permissions as $permission) {
        if ($user->hasPermission($permission)) {
            return $next($request);
        }
    }

    \Log::warning('Acceso denegado', [
        'user_id'     => $user->id,
        'permissions' => $permissions,
        'endpoint'    => $request->path(),
        'restaurante' => $user->restaurante_activo,
    ]);

    return response()->json([
        'success' => false,
        'message' => 'No tienes permisos suficientes'
    ], 403);
}
}