<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

use Illuminate\Support\Facades\DB;

try {
    $results = DB::select("SHOW COLUMNS FROM ordenes LIKE 'id'");
    echo "<pre>";
    print_r($results);
    echo "</pre>";

    if (!empty($results) && strpos($results[0]->Extra, 'auto_increment') === false) {
        echo "<h3 style='color:red'>EL ID NO TIENE AUTO_INCREMENT</h3>";
        echo "<p>Ejecuta esto en tu DB:</p>";
        echo "<code>ALTER TABLE ordenes MODIFY id INT AUTO_INCREMENT;</code>";
    } else {
        echo "<h3 style='color:green'>EL ID PARECE ESTAR BIEN</h3>";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
