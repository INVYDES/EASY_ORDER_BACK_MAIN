<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\Mail;
use App\Mail\Transport\GmailApiTransport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // NOTA: El restaurante_activo se establece en el middleware 'tenant'
        // EnsureTenantSelected.php hace: app()->instance('restaurante_activo', $restaurante)
        // No registramos binding aquí para evitar conflictos con instance()
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Forzar HTTPS en producción/túneles - Versión sin fachada
        if (env('APP_ENV') !== 'local' && env('APP_FORCE_HTTPS', false)) {
            \URL::forceScheme('https');
        }

        // 🔒 SEGURIDAD: Limitar intentos de login (5 por minuto por IP)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // 🔒 SEGURIDAD: Limitar consultas pesadas de reportes (10 por minuto)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Registrar driver de correo Gmail API via OAuth2
        Mail::extend('gmail-api', function (array $config = []) {
            return new GmailApiTransport(
                $config['client_id'] ?? env('GOOGLE_CLIENT_ID'),
                $config['client_secret'] ?? env('GOOGLE_CLIENT_SECRET', ''),
                $config['refresh_token'] ?? env('GOOGLE_REFRESH_TOKEN', '')
            );
        });
    }
}