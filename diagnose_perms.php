<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Role;
use App\Models\Permission;

$user = User::where('username', 'admin')->first();
if (!$user) {
    echo "Usuario admin no encontrado\n";
    exit;
}

echo "Usuario: {$user->name} (ID: {$user->id})\n";
echo "Roles:\n";
foreach ($user->roles as $role) {
    echo " - {$role->nombre}\n";
    echo "   Permisos:\n";
    foreach ($role->permissions as $perm) {
        echo "     * {$perm->nombre}\n";
    }
}

$permName = 'VER_EMPLEADOS';
$perm = Permission::where('nombre', $permName)->first();
if (!$perm) {
    echo "\n¡ADVERTENCIA!: El permiso '{$permName}' NO EXISTE en la tabla de permisos.\n";
} else {
    echo "\nEl permiso '{$permName}' existe.\n";
}
