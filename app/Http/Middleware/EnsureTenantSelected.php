<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureTenantSelected
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

        // OPCIÓN PARA CLIENTES: Si envían restaurante_id por parámetro, usar ese.
        $restauranteIdParam = $request->get('restaurante_id') ?? $request->header('X-Restaurante-Id');
        
        if ($restauranteIdParam && $user->hasRole('CLIENTE')) {
            $restauranteActivo = \App\Models\Restaurante::find($restauranteIdParam);
            if ($restauranteActivo) {
                app()->instance('restaurante_activo', $restauranteActivo);
                $request->merge(['restaurante_activo' => $restauranteActivo]);
                return $next($request);
            }
        }

        // Si no tiene restaurante activo, intentar asignar el primero
        if (!$user->restaurante_activo) {
            // Intentar primero como dueño, luego como asignado
            $primerRestaurante = ($user->propietario_id) 
                ? $user->restaurantesDelPropietario()->first() 
                : $user->restaurantes()->first();
            
            if ($primerRestaurante) {
                $user->restaurante_activo = $primerRestaurante->id;
                $user->save();
            } else if ($user->hasRole('CLIENTE')) {
                return $next($request);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes restaurantes asignados. Contacta al administrador.'
                ], 403);
            }
        }

        // Compartir el restaurante activo con toda la aplicación
        $restauranteActivo = $user->restauranteActivo;

        // Si el ID guardado no corresponde a un registro real, buscar el primero disponible
        if (!$restauranteActivo) {
            $primerRestaurante = ($user->propietario_id) 
                ? $user->restaurantesDelPropietario()->first() 
                : $user->restaurantes()->first();
                
            if ($primerRestaurante) {
                $user->restaurante_activo = $primerRestaurante->id;
                $user->save();
                $restauranteActivo = $primerRestaurante;
            } else {
                 return response()->json([
                    'success' => false,
                    'message' => 'No tienes restaurantes activos o asignados.'
                ], 403);
            }
        }

        app()->instance('restaurante_activo', $restauranteActivo);
        
        // También lo agregamos al request para fácil acceso
        $request->merge(['restaurante_activo' => $restauranteActivo]);

        return $next($request);
    }
}