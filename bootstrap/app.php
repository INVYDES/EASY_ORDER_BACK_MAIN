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
            // `auth:sanctum` debe resolver a nuestro middleware: sin este alias
            // se usaba el de Laravel, que intentaba redirigir a la ruta `login`
            // (inexistente en una API) y terminaba en un 500 en vez de un 401.
            'auth'       => \App\Http\Middleware\Authenticate::class,
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
            // Falta de autenticación: siempre 401 JSON, en cualquier ruta
            // (api/* y también /broadcasting/auth). Sin esto Laravel intentaba
            // redirigir a la ruta `login` (inexistente) y terminaba en 500/404.
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                    'errors'  => null,
                    'debug'   => null,
                ], 401);
            }

            if ($request->is('api/*')) {
                $status = 500;
                if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                    $status = $e->getStatusCode();
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
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        $schedule->command('app:notificar-suscripcion-por-vencer')->dailyAt('09:00');
    })->create();