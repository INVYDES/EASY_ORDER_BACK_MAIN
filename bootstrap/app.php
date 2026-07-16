<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../resources/routes/api.php',
        apiPrefix: 'api',
        channels: __DIR__.'/../routes/channels.php',
    )
    ->withBroadcasting(__DIR__.'/../routes/channels.php', attributes: ['middleware' => ['api', 'auth:sanctum']])
    ->withMiddleware(function (Middleware $middleware) {

        $middleware->alias([
            'tenant'     => \App\Http\Middleware\EnsureTenantSelected::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
            'broadcasting/*',
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
                        'file'  => $e->getFile(),
                        'line'  => $e->getLine(),
                        'trace' => array_slice($e->getTrace(), 0, 5),
                    ] : null,
                ], $status);
            }

            if ($e instanceof \Symfony\Component\Routing\Exception\RouteNotFoundException) {
                return response()->json(['success' => false, 'message' => 'Ruta no encontrada'], 404);
            }
        });
    })->create();