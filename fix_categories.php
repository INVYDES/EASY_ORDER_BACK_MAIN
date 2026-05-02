<?php

use App\Models\Categoria;
use App\Models\Restaurante;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$restauranteId = 4; // El Faro
$restaurante = Restaurante::find($restauranteId);

if (!$restaurante) {
    die("Restaurante ID $restauranteId no encontrado.\n");
}

$categoriasBase = [
    ['nombre' => 'Cocina', 'color' => '#10B981'],
    ['nombre' => 'Barra',  'color' => '#6366F1'],
    ['nombre' => 'Postres', 'color' => '#EC4899'],
];

echo "Reparando categorías para: " . $restaurante->nombre . "\n";

foreach ($categoriasBase as $cat) {
    $existe = Categoria::where('restaurante_id', $restauranteId)
                       ->where('nombre', $cat['nombre'])
                       ->exists();
    
    if (!$existe) {
        Categoria::create([
            'restaurante_id' => $restauranteId,
            'nombre' => $cat['nombre'],
            'color'  => $cat['color'],
            'activo' => true,
            'orden'  => 0
        ]);
        echo "✅ Categoría '{$cat['nombre']}' creada.\n";
    } else {
        echo "ℹ️ La categoría '{$cat['nombre']}' ya existía.\n";
    }
}

echo "Proceso finalizado.\n";
