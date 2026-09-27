<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Ingrediente;
use App\Models\IngredienteMovimiento;
use App\Models\Paquete;
use App\Models\Producto;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo completo exportar → importar (productos, paquetes e ingredientes).
 *
 * IMPORTANTE: estos tests ESCRIBEN filas, por eso solo corren contra una base de
 * datos de pruebas. Si la conexión apunta a cualquier otra base, se omiten (así
 * nunca tocan producción).
 *
 * Para ejecutarlos hace falta una base local cargada desde un dump, por ejemplo:
 *
 *   mysql -u root -e "CREATE DATABASE easy_order_test"
 *   mysql -u root easy_order_test < railway_full_dump.sql
 *
 * Y correr con:
 *
 *   APP_ENV=local DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=easy_order_test \
 *   DB_USERNAME=root DB_PASSWORD=*** vendor/bin/phpunit --no-configuration \
 *   --bootstrap vendor/autoload.php tests/Feature/ExportImportCicloTest.php
 *
 * (phpunit.xml fuerza SQLite en memoria, de ahí el --no-configuration.)
 *
 * Nota: el import empareja por nombre (igual que el de productos), así que un
 * catálogo con nombres repetidos (el dump trae "Americano" x2, "Mojaditos"/"mojaditos")
 * hace que dos filas apunten al mismo registro. Es una limitación conocida del
 * diseño por nombre, no un fallo del ciclo.
 */
class ExportImportCicloTest extends TestCase
{
    /** Única base donde se permite escribir. */
    private const DB_PERMITIDA = 'easy_order_test';

    /** Restaurantes del dump de pruebas: el 1 es chico, el 2 supera los 100 productos. */
    private const USER_ID       = 1;
    private const RESTAURANTE_1 = 1;
    private const RESTAURANTE_2 = 2;

    protected function setUp(): void
    {
        parent::setUp();

        $actual = DB::connection()->getDatabaseName();

        if ($actual !== self::DB_PERMITIDA) {
            $this->markTestSkipped(
                'Test destructivo: requiere la BD de pruebas "' . self::DB_PERMITIDA . '". '
                . 'Conexión actual: "' . $actual . '" (no se escribió nada).'
            );
        }
    }

    // ── HELPERS ───────────────────────────────────────────────────────────

    private function comoPropietario(int $restauranteId): array
    {
        $this->actingAs(User::find(self::USER_ID), 'sanctum');

        return ['X-Restaurante-Id' => (string) $restauranteId];
    }

    /**
     * Convierte el CSV del export en [cabeceras, filas]. Se usa fgetcsv sobre un
     * stream para respetar comillas y saltos de línea embebidos.
     */
    private function parsearCsv(string $contenido): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, ltrim($contenido, "\xEF\xBB\xBF"));
        rewind($stream);

        $filas = [];
        while (($fila = fgetcsv($stream)) !== false) {
            if ($fila === [null]) {
                continue;
            }
            $filas[] = $fila;
        }
        fclose($stream);

        $cabeceras = array_shift($filas);

        return [$cabeceras, $filas];
    }

    private function exportar(string $endpoint, int $restauranteId): array
    {
        $resp = $this->get($endpoint, $this->comoPropietario($restauranteId));
        $resp->assertOk();

        return $this->parsearCsv($resp->streamedContent());
    }

    /** Filas del CSV (con cabeceras ya separadas) listas para reimportar. */
    private function filasAProductos(array $filas): array
    {
        return array_map(fn ($f) => [
            'nombre'       => $f[0],
            'precio'       => (float) $f[1],
            'descripcion'  => $f[2],
            'categoria_id' => trim((string) $f[3]) !== '' ? (int) $f[3] : null,
            'categoria'    => $f[4],
            'stock'        => (int) $f[5],
            'stock_minimo' => (int) $f[6],
            'minutos_produccion' => (int) $f[7],
        ], $filas);
    }

    /**
     * Lee un ZIP *stored* (sus entradas van sin comprimir). Se parsea a mano
     * porque el servidor no tiene la extensión `zip` (ZipArchive) disponible.
     *
     * @return array<string, string> nombre de la entrada => contenido
     */
    private function leerZipStored(string $zip): array
    {
        // Fin del directorio central: firma 0x06054b50 ("PK\x05\x06").
        $eocd = strrpos($zip, "PK\x05\x06");
        $this->assertNotFalse($eocd, 'El archivo no trae fin de directorio central: no es un ZIP.');

        $total    = unpack('v', substr($zip, $eocd + 10, 2))[1];
        $cdOffset = unpack('V', substr($zip, $eocd + 16, 4))[1];

        $entradas = [];
        $p        = $cdOffset;

        for ($i = 0; $i < $total; $i++) {
            $this->assertSame(
                0x02014b50,
                unpack('V', substr($zip, $p, 4))[1],
                'Cabecera del directorio central corrupta.'
            );

            $method      = unpack('v', substr($zip, $p + 10, 2))[1];
            $compSize    = unpack('V', substr($zip, $p + 20, 4))[1];
            $nameLen     = unpack('v', substr($zip, $p + 28, 2))[1];
            $extraLen    = unpack('v', substr($zip, $p + 30, 2))[1];
            $commentLen  = unpack('v', substr($zip, $p + 32, 2))[1];
            $localOffset = unpack('V', substr($zip, $p + 42, 4))[1];

            $name = substr($zip, $p + 46, $nameLen);

            // Datos = cabecera local (30 bytes) + nombre + campo extra.
            $localNameLen  = unpack('v', substr($zip, $localOffset + 26, 2))[1];
            $localExtraLen = unpack('v', substr($zip, $localOffset + 28, 2))[1];
            $dataOffset    = $localOffset + 30 + $localNameLen + $localExtraLen;

            $this->assertSame(0, $method, "La entrada {$name} debería ir sin comprimir (stored).");

            $entradas[$name] = substr($zip, $dataOffset, $compSize);

            $p += 46 + $nameLen + $extraLen + $commentLen;
        }

        return $entradas;
    }

    // ── PRODUCTOS ─────────────────────────────────────────────────────────

    public function test_export_de_productos_incluye_la_columna_categoria(): void
    {
        [$cabeceras, $filas] = $this->exportar('/api/productos/export', self::RESTAURANTE_1);

        $this->assertSame(
            ['nombre', 'precio', 'descripcion', 'categoria_id', 'categoria', 'stock', 'stock_minimo', 'minutos_produccion'],
            $cabeceras
        );

        $this->assertNotEmpty($filas);

        // La columna extra debe traer el nombre de la categoría, no venir vacía.
        $conNombre = array_filter($filas, fn ($f) => trim((string) $f[4]) !== '');
        $this->assertNotEmpty($conNombre, 'La columna `categoria` debe traer el nombre de la categoría.');

        // Y ese nombre debe corresponder al categoria_id de la misma fila.
        foreach ($conNombre as $fila) {
            $categoria = Categoria::find((int) $fila[3]);
            $this->assertNotNull($categoria, "No existe la categoría {$fila[3]}");
            $this->assertSame($categoria->nombre, $fila[4]);
        }
    }

    /**
     * `?formato=xlsx` arma el libro en el servidor (sin SheetJS en el navegador).
     * Se comprueba que el ZIP traiga las 5 partes del formato OOXML y que la fila
     * de cabeceras sea la misma —y en el mismo orden— que la del CSV.
     */
    public function test_export_de_productos_en_xlsx_devuelve_zip_con_las_partes_y_cabeceras(): void
    {
        $resp = $this->get('/api/productos/export?formato=xlsx', $this->comoPropietario(self::RESTAURANTE_1));

        $resp->assertOk();
        $this->assertStringContainsString(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $resp->headers->get('Content-Type')
        );
        $this->assertStringContainsString('.xlsx', $resp->headers->get('Content-Disposition'));

        $partes = $this->leerZipStored($resp->streamedContent());

        $this->assertSame(
            [
                '[Content_Types].xml',
                '_rels/.rels',
                'xl/workbook.xml',
                'xl/_rels/workbook.xml.rels',
                'xl/worksheets/sheet1.xml',
            ],
            array_keys($partes),
            'El .xlsx debe contener las 5 partes del formato OOXML.'
        );

        // Cada parte tiene que ser XML bien formado.
        foreach ($partes as $nombre => $contenido) {
            $this->assertNotFalse(simplexml_load_string($contenido), "La parte {$nombre} no es XML válido.");
        }

        // En Excel el libro se llama como la hoja pedida al writer.
        $this->assertStringContainsString('name="Productos"', $partes['xl/workbook.xml']);

        $sheet = simplexml_load_string($partes['xl/worksheets/sheet1.xml']);
        $sheet->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $cabeceras = [];
        foreach ($sheet->xpath('//x:sheetData/x:row[@r="1"]/x:c') as $celda) {
            $cabeceras[] = (string) $celda->is->t;
        }

        $this->assertSame(
            ['nombre', 'precio', 'descripcion', 'categoria_id', 'categoria', 'stock', 'stock_minimo', 'minutos_produccion'],
            $cabeceras
        );

        // Y no es solo la cabecera: el primer producto sale en la fila 2.
        $this->assertNotEmpty($sheet->xpath('//x:sheetData/x:row[@r="2"]'));
    }

    public function test_reimportar_el_export_de_productos_actualiza_los_existentes(): void
    {
        [, $filas] = $this->exportar('/api/productos/export', self::RESTAURANTE_1);
        $this->assertNotEmpty($filas);

        $productos = $this->filasAProductos($filas);

        $resp = $this->postJson('/api/productos/import', [
            'productos'        => $productos,
            'sobrescribir'     => true,
            'crear_categorias' => false,
        ], $this->comoPropietario(self::RESTAURANTE_1));

        $resp->assertOk();
        $resp->assertJsonPath('success', true);
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertSame(count($productos), $resp->json('data.actualizados'));
        $this->assertSame(0, $resp->json('data.creados'));
    }

    /**
     * El export no tiene tope y el catálogo real supera los 100 productos: sin
     * subir el límite del import, el archivo exportado no se podía reimportar.
     */
    public function test_reimportar_un_catalogo_de_mas_de_100_productos(): void
    {
        [, $filas] = $this->exportar('/api/productos/export', self::RESTAURANTE_2);

        $this->assertGreaterThan(100, count($filas), 'El restaurante 2 debería exportar más de 100 productos.');

        $productos = $this->filasAProductos($filas);

        $resp = $this->postJson('/api/productos/import', [
            'productos'    => $productos,
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_2));

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertSame(count($productos), $resp->json('data.actualizados'));
    }

    public function test_import_crea_la_categoria_por_nombre_si_no_existe(): void
    {
        $nombreCategoria = 'Categoria Prueba Ciclo';
        Categoria::withTrashed()->where('nombre', $nombreCategoria)
            ->where('restaurante_id', self::RESTAURANTE_1)->forceDelete();

        $resp = $this->postJson('/api/productos/import', [
            'productos' => [[
                'nombre'       => 'Producto Categoria Por Nombre',
                'precio'       => 99.5,
                'categoria'    => $nombreCategoria,
                'stock'        => 3,
                'stock_minimo' => 1,
            ]],
            'sobrescribir'     => true,
            'crear_categorias' => true,
        ], $this->comoPropietario(self::RESTAURANTE_1));

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertSame(1, $resp->json('data.creados'));

        $categoria = Categoria::where('nombre', $nombreCategoria)
            ->where('restaurante_id', self::RESTAURANTE_1)->first();
        $this->assertNotNull($categoria, 'La categoría debió crearse a partir del nombre.');

        $producto = Producto::where('nombre', 'Producto Categoria Por Nombre')
            ->where('restaurante_id', self::RESTAURANTE_1)->first();
        $this->assertNotNull($producto);
        $this->assertSame($categoria->id, (int) $producto->categoria_id);

        // Limpieza
        $producto->forceDelete();
        $categoria->forceDelete();
    }

    // ── PAQUETES ──────────────────────────────────────────────────────────

    public function test_ciclo_completo_de_paquetes(): void
    {
        [$cabeceras, $filas] = $this->exportar('/api/paquetes/export', self::RESTAURANTE_2);

        $this->assertSame(
            ['nombre', 'precio', 'descripcion', 'activo', 'stock', 'productos'],
            $cabeceras
        );
        $this->assertNotEmpty($filas);

        // Todas las filas deben traer el contenido del combo resuelto por nombre.
        foreach ($filas as $fila) {
            $this->assertNotSame('', trim((string) $fila[5]), "El paquete {$fila[0]} no trae productos.");
        }

        $paquetes = array_map(fn ($f) => [
            'nombre'      => $f[0],
            'precio'      => (float) $f[1],
            'descripcion' => $f[2],
            'activo'      => $f[3],
            'productos'   => $f[5],
        ], $filas);

        $resp = $this->postJson('/api/paquetes/import', [
            'paquetes'     => $paquetes,
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_2));

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertSame(count($paquetes), $resp->json('data.actualizados'));
        $this->assertSame(0, $resp->json('data.creados'));
    }

    public function test_import_de_paquete_falla_si_un_producto_del_combo_no_existe(): void
    {
        $resp = $this->postJson('/api/paquetes/import', [
            'paquetes' => [[
                'nombre'    => 'Combo Incompleto',
                'precio'    => 100,
                'productos' => 'Producto Que No Existe x1',
            ]],
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_1));

        $resp->assertOk();
        $this->assertSame(0, $resp->json('data.creados'));
        $this->assertCount(1, $resp->json('data.errores'));
        $this->assertStringContainsString('no encontrado', $resp->json('data.errores.0.error'));

        // Y no debe haberse creado a medias.
        $this->assertNull(
            Paquete::where('nombre', 'Combo Incompleto')->where('restaurante_id', self::RESTAURANTE_1)->first()
        );
    }

    // ── INGREDIENTES ──────────────────────────────────────────────────────

    public function test_ciclo_completo_de_ingredientes(): void
    {
        [$cabeceras, $filas] = $this->exportar('/api/ingredientes/export', self::RESTAURANTE_2);

        $this->assertSame(
            ['nombre', 'unidad', 'costo_unitario', 'stock_actual', 'stock_minimo', 'proveedor', 'activo'],
            $cabeceras
        );
        $this->assertNotEmpty($filas);

        $ingredientes = array_map(fn ($f) => [
            'nombre'         => $f[0],
            'unidad'         => $f[1],
            'costo_unitario' => (float) $f[2],
            'stock_actual'   => (float) $f[3],
            'stock_minimo'   => (float) $f[4],
            'proveedor'      => $f[5],
            'activo'         => $f[6],
        ], $filas);

        $totalAntes = Ingrediente::where('restaurante_id', self::RESTAURANTE_2)->count();

        $resp = $this->postJson('/api/ingredientes/import', [
            'ingredientes' => $ingredientes,
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_2));

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertSame(count($ingredientes), $resp->json('data.actualizados'));
        $this->assertSame(0, $resp->json('data.creados'));
        $this->assertSame($totalAntes, Ingrediente::where('restaurante_id', self::RESTAURANTE_2)->count());
    }

    public function test_import_de_ingrediente_registra_movimiento_al_cambiar_stock(): void
    {
        $ingrediente   = Ingrediente::where('restaurante_id', self::RESTAURANTE_1)->firstOrFail();
        $stockOriginal = (float) $ingrediente->stock_actual;
        $id = $ingrediente->id;

        IngredienteMovimiento::where('ingrediente_id', $id)->delete();

        $resp = $this->postJson('/api/ingredientes/import', [
            'ingredientes' => [[
                'nombre'       => $ingrediente->nombre,
                'unidad'       => $ingrediente->unidad,
                'stock_actual' => $stockOriginal + 5,
            ]],
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_1));

        // El movimiento se calcula y se restaura el stock ANTES de assertar, para
        // no dejar residuo en la BD de pruebas si algo falla.
        $movimiento = IngredienteMovimiento::where('ingrediente_id', $id)->first();

        $ingrediente->refresh();
        $ingrediente->update(['stock_actual' => $stockOriginal]);

        $resp->assertOk();
        $this->assertSame(1, $resp->json('data.actualizados'));

        $this->assertNotNull($movimiento, 'El cambio de stock debe quedar en el historial.');
        $this->assertSame('entrada', $movimiento->tipo);
        $this->assertSame(5.0, (float) $movimiento->cantidad_movimiento);
        $this->assertSame($stockOriginal, (float) $movimiento->cantidad_anterior);
    }

    public function test_import_de_ingrediente_puede_dejar_stock_en_cero(): void
    {
        // Caso que con tipo 'ajuste' no se podía registrar (el modelo exige que
        // cantidad_movimiento sea mayor que cero).
        $ingrediente = Ingrediente::where('restaurante_id', self::RESTAURANTE_1)->firstOrFail();
        $stockOriginal = (float) $ingrediente->stock_actual;

        $resp = $this->postJson('/api/ingredientes/import', [
            'ingredientes' => [[
                'nombre'       => $ingrediente->nombre,
                'unidad'       => $ingrediente->unidad,
                'stock_actual' => 0,
            ]],
            'sobrescribir' => true,
        ], $this->comoPropietario(self::RESTAURANTE_1));

        $movimiento = IngredienteMovimiento::where('ingrediente_id', $ingrediente->id)
            ->where('tipo', 'salida')->first();

        $ingrediente->refresh();
        $ingrediente->update(['stock_actual' => $stockOriginal]);

        $resp->assertOk();
        $this->assertSame([], $resp->json('data.errores'));
        $this->assertNotNull($movimiento, 'Dejar el stock en 0 también debe registrarse.');
    }
}
