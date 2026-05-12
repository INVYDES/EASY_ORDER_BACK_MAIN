<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "<h2>🛠 Reparando Auto-Incrementos</h2>";

try {
    echo "<li>Corrigiendo tabla 'ordenes'... ";
    DB::statement("ALTER TABLE ordenes MODIFY id BIGINT UNSIGNED AUTO_INCREMENT");
    echo "<span style='color:green'>¡Hecho!</span></li>";

    echo "<li>Corrigiendo tabla 'orden_detalles'... ";
    DB::statement("ALTER TABLE orden_detalles MODIFY id BIGINT UNSIGNED AUTO_INCREMENT");
    echo "<span style='color:green'>¡Hecho!</span></li>";

    echo "<li>Corrigiendo tabla 'categorias'... ";
    DB::statement("ALTER TABLE categorias MODIFY id BIGINT UNSIGNED AUTO_INCREMENT");
    echo "<span style='color:green'>¡Hecho!</span></li>";

    echo "<li>Corrigiendo tabla 'productos'... ";
    DB::statement("ALTER TABLE productos MODIFY id BIGINT UNSIGNED AUTO_INCREMENT");
    echo "<span style='color:green'>¡Hecho!</span></li>";

    echo "<h3 style='color:green'>✅ ¡Tablas reparadas!</h3>";
    echo "<p>Intenta crear una orden ahora.</p>";

} catch (\Exception $e) {
    echo "<h3 style='color:red'>❌ Falló la reparación:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
