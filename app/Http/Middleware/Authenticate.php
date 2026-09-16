<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }

    /**
     * Handle unauthenticated requests for APIs
     * Debe LANZAR la excepción (no retornar): el handler global de
     * bootstrap/app.php la convierte en JSON 401 para rutas api/*.
     */
    protected function unauthenticated($request, array $guards)
    {
        throw new \Illuminate\Auth\AuthenticationException('No autenticado');
    }
}