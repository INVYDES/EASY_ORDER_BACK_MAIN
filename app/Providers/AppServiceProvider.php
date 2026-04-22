<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
            \URL::forceScheme('https');  // ✅ Usando la clase global con \
        }
    }
}