<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckLicenciaPlan
{
    public function handle(Request $request, Closure $next, ...$permisos)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'No autenticado'
            ], 401);
        }

        if (!$user->propietario_id) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes un propietario asignado'
            ], 403);
        }

        $propietario = $user->propietario;

        if (!$propietario) {
            return response()->json([
                'success' => false,
                'message' => 'Propietario no encontrado'
            ], 403);
        }

        $licenciaActiva = $propietario->getLicenciaActiva();

        if (!$licenciaActiva || !$licenciaActiva->licencia) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes una licencia activa'
            ], 403);
        }

        $tienePermiso = false;
        $tieneRol = false;

        foreach ($permisos as $permiso) {
            if ($licenciaActiva->licencia->tienePermiso($permiso)) {
                $tienePermiso = true;
            }
            if ($user->hasPermission($permiso)) {
                $tieneRol = true;
            }
        }

        if (!$tienePermiso) {
            return response()->json([
                'success' => false,
                'message' => 'Tu plan de licencia no incluye este permiso'
            ], 403);
        }

        if (!$tieneRol) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos suficientes'
            ], 403);
        }

        return $next($request);
    }
}
