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
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                $status = 500;
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $status = $e->getStatusCode();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $status = 401;
                } elseif ($e instanceof \Illuminate\Validation\ValidationException) {
                    $status = 422;
                }

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors'  => ($e instanceof \Illuminate\Validation\ValidationException) ? $e->errors() : null,
                    'debug'   => config('app.debug') ? [
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                        'trace' => array_slice($e->getTrace(), 0, 5)
                    ] : null,
                ], $status);
            }
        });
    })->create();