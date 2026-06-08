<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = DB::table('users')->select('id','name','email')->take(5)->get();
foreach ($users as $u) {
    echo "{$u->id}: {$u->name} ({$u->email})\n";
}

echo "---\n";

$licencias = DB::table('licencias')->select('id','nombre','tipo','precio','activo')->get();
foreach ($licencias as $l) {
    echo "{$l->id}: {$l->nombre} ({$l->tipo}) \${$l->precio} active=" . ($l->activo ? 'yes' : 'no') . "\n";
}
