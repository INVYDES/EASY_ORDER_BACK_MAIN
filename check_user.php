<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "--- Users with propietario_id ---\n";
$users = DB::table('users')->select('id','name','email','propietario_id')->whereNotNull('propietario_id')->get();
foreach ($users as $u) {
    echo "{$u->id}: {$u->name} ({$u->email}) propietario_id={$u->propietario_id}\n";
}

echo "\n--- All propietarios ---\n";
$props = DB::table('propietarios')->get();
foreach ($props as $p) echo "id={$p->id}: {$p->nombre} {$p->apellido}\n";

echo "\n--- PropietarioLicencia records ---\n";
$pls = DB::table('propietario_licencia')->select('id','propietario_id','licencia_id','estado','fecha_expiracion','metodo_pago')->get();
foreach ($pls as $pl) {
    echo "{$pl->id}: prop={$pl->propietario_id} lic={$pl->licencia_id} estado={$pl->estado} expira={$pl->fecha_expiracion} metodo={$pl->metodo_pago}\n";
}
