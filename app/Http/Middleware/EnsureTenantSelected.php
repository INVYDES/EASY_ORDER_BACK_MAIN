<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\Restaurante;

class EnsureTenantSelected
{
    /**
     * Middleware de Multi-tenancy optimizado.
     * Garantiza que exista un 'restaurante_activo' en el contenedor de la app.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (!$user || $user->hasRole('SUPER_ADMIN')) {
            return $next($request);
        }

        // 1. PRIORIDAD: Header o Parámetro (Switch dinámico)
        $restauranteId = $request->header('X-Restaurante-Id') ?? $request->get('restaurante_id');

        if ($restauranteId) {
            $restaurante = $this->getRestauranteCached($restauranteId);
            
            if ($restaurante) {
                app()->instance('restaurante_activo', $restaurante);
                $request->attributes->set('restaurante_activo', $restaurante);
                return $next($request);
            }
        }

        // 2. RESPALDO: Usar el restaurante_activo del usuario en la DB
        if (!$user->restaurante_activo) {
            $primerId = $this->getPrimerRestauranteId($user);
            
            if ($primerId) {
                $user->update(['restaurante_activo' => $primerId]);
                // Invalidar caché de búsqueda inicial para forzar recarga en siguiente petición
                Cache::forget("user_first_res_{$user->id}");
            } elseif (!$user->hasRole('CLIENTE')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes sucursales asignadas.'
                ], 403);
            }
        }

        // 3. CARGAR INSTANCIA GLOBAL
        if ($user->restaurante_activo) {
            $restaurante = $this->getRestauranteCached($user->restaurante_activo);
            
            if ($restaurante) {
                app()->instance('restaurante_activo', $restaurante);
                $request->attributes->set('restaurante_activo', $restaurante);
            } else {
                // Auto-sanación: Si el ID guardado ya no existe, buscar el siguiente disponible
                $nuevoId = $this->getPrimerRestauranteId($user);
                if ($nuevoId) {
                    $user->update(['restaurante_activo' => $nuevoId]);
                    Cache::forget("user_first_res_{$user->id}");
                    
                    $nuevoRes = $this->getRestauranteCached($nuevoId);
                    if ($nuevoRes) {
                        app()->instance('restaurante_activo', $nuevoRes);
                        $request->attributes->set('restaurante_activo', $nuevoRes);
                    }
                }
            }
        }

        return $next($request);
    }

    /**
     * Obtiene el restaurante con caché corto (120s) por si hay cambios en BD.
     */
    private function getRestauranteCached($id)
    {
        if (!$id) return null;
        return Cache::remember("tenant_res_{$id}", 120, fn() => Restaurante::find($id));
    }

    /**
     * Busca la primera sucursal disponible para el usuario.
     */
    private function getPrimerRestauranteId($user)
    {
        return Cache::remember("user_first_res_{$user->id}", 300, function() use ($user) {
            $res = ($user->propietario_id) 
                ? $user->restaurantesDelPropietario()->first() 
                : $user->restaurantes()->first();
            return $res ? $res->id : null;
        });
    }
}