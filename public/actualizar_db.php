<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;

// Cargar el entorno de Laravel
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$response = $kernel->handle(
    $request = Illuminate\Http\Request::capture()
);

echo "<h2>🔧 Actualizando Base de Datos</h2>";

try {
    // 1. Correr migraciones pendientes por si acaso
    echo "<li>Ejecutando migraciones pendientes... ";
    Artisan::call('migrate', ['--force' => true]);
    echo "<span style='color:green'>¡Listo!</span></li>";

    // 2. Verificar específicamente la columna tipo_orden
    if (!Schema::hasColumn('ordenes', 'tipo_orden')) {
        echo "<li>Agregando columnas de Delivery a la tabla 'ordenes'... ";
        Schema::table('ordenes', function (Blueprint $table) {
            $table->enum('tipo_orden', ['local', 'pickup', 'delivery'])->default('local')->after('cliente_id');
            $table->string('direccion_entrega', 500)->nullable()->after('tipo_orden');
            $table->string('telefono_contacto', 20)->nullable()->after('direccion_entrega');
            $table->decimal('costo_envio', 10, 2)->default(0)->after('telefono_contacto');
            $table->integer('tiempo_estimado_entrega')->nullable()->after('costo_envio');
        });
        echo "<span style='color:green'>¡Listo!</span></li>";
    } else {
        echo "<li>La columna 'tipo_orden' ya existe. <span style='color:blue'>Omitiendo.</span></li>";
    }

    echo "<h3 style='color:green'>✅ ¡Base de datos actualizada con éxito!</h3>";
    echo "<p>Ya puedes borrar este archivo (actualizar_db.php) y probar tu sistema.</p>";

} catch (\Exception $e) {
    echo "<h3 style='color:red'>❌ Error durante la actualización:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
