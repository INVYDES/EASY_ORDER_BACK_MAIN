<?php
// public/test.php
require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$router = $app->make('router');

echo "Middlewares registrados:\n";
print_r(array_keys($router->getMiddleware()));

echo "\n¿Existe 'permission'? " . (isset($router->getMiddleware()['permission']) ? 'SÍ' : 'NO');