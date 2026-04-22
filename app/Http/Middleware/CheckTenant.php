<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }

        if (!$user->restaurante_activo) {
            $restaurante = $user->restaurantes()->first();

            if (!$restaurante) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes restaurantes asignados'
                ], 403);
            }

            $user->restaurante_activo = $restaurante->id;
            $user->save();
        }

        $restauranteActivo = $user->restauranteActivo;
        app()->instance('restaurante_activo', $restauranteActivo);
        $request->attributes->set('restaurante_activo', $restauranteActivo);

        return $next($request);
    }
}