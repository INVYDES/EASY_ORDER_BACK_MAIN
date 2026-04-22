<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureTenantSelected;

class MiddlewareServiceProvider extends ServiceProvider
{
    public function register()
    {
        //
    }

    public function boot()
    {
        $router = $this->app['router'];
        
        // Registrar middlewares manualmente
        $router->aliasMiddleware('permission', CheckPermission::class);
        $router->aliasMiddleware('tenant', EnsureTenantSelected::class);
    }
}