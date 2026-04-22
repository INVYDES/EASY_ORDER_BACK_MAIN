<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        
        // Aliases de middlewares
        $middleware->alias([
            'tenant' => \App\Http\Middleware\EnsureTenantSelected::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
        
        // Middleware para API (CORS y Sanctum)
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        
        // Excluir rutas API de CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);
        
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();