<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\Orden;
use App\Models\Categoria;
use App\Models\Restaurante;
use App\Models\Propietario;
use App\Models\Licencia;
use App\Models\PropietarioLicencia;
use App\Models\Log;
use App\Policies\ProductoPolicy;
use App\Policies\ClientePolicy;
use App\Policies\OrdenPolicy;
use App\Policies\CategoriaPolicy;
use App\Policies\RestaurantePolicy;
use App\Policies\PropietarioPolicy;
use App\Policies\LicenciaPolicy;
use App\Policies\PropietarioLicenciaPolicy;
use App\Policies\LogPolicy;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Producto::class => ProductoPolicy::class,
        Cliente::class => ClientePolicy::class,
        Orden::class => OrdenPolicy::class,
        Categoria::class => CategoriaPolicy::class,
        Restaurante::class => RestaurantePolicy::class,
        Propietario::class => PropietarioPolicy::class,
        Licencia::class => LicenciaPolicy::class,
        PropietarioLicencia::class => PropietarioLicenciaPolicy::class,
        Log::class => LogPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}