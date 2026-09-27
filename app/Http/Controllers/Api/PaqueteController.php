<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OrdenDetalle;
use App\Models\Paquete;
use App\Models\Producto;
use App\Models\User;
use App\Helpers\XlsxWriter;
use App\Traits\DetectaCambioDePrecioEnOrdenes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PaqueteController extends Controller
{
    use DetectaCambioDePrecioEnOrdenes;

    public function index(Request $request)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquetes = Paquete::with(['productos.categoria', 'productos.ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->when($request->filled('buscar'), function($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->buscar}%");
                })
                ->get();

            $data = $paquetes->map(fn($p) => $this->formatPaqueteResponse($p));

            return response()->json([
                'success' => true,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al obtener paquetes', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Exportar paquetes a CSV.
     *
     * Incluye el contenido del combo (productos y cantidades) para que el
     * archivo siga siendo legible fuera del sistema.
     *
     * GET /api/paquetes/export
     */
    public function export(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user->hasPermission('VER_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permiso para exportar paquetes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            $query = Paquete::with('productos')
                ->where('restaurante_id', $restauranteActivo->id)
                ->when($request->filled('buscar'), function ($q) use ($request) {
                    $q->where('nombre', 'LIKE', "%{$request->buscar}%");
                });

            // .xlsx generado en el servidor: para catálogos enormes evita que el
            // navegador tenga que parsear el CSV completo con SheetJS.
            if (strtolower((string) $request->get('formato', 'csv')) === 'xlsx') {
                $nombreXlsx = 'paquetes_' . now()->format('Ymd_His') . '.xlsx';

                return response()->streamDownload(function () use ($query) {
                    $xlsx = new XlsxWriter('Paquetes');
                    $xlsx->addRow(['nombre', 'precio', 'descripcion', 'activo', 'stock', 'productos']);

                    foreach ($query->orderBy('nombre')->orderBy('id')->lazy(500) as $paquete) {
                        $contenido = $paquete->productos->map(function ($producto) {
                            $cantidad = (float) ($producto->pivot->cantidad ?? 1);
                            $cantidad = rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');
                            return $producto->nombre . ' x' . $cantidad;
                        })->implode(' | ');

                        $unidadesPosibles = $paquete->productos
                            ->filter(fn($producto) => (float) ($producto->pivot->cantidad ?? 1) > 0)
                            ->map(fn($producto) => floor((float) $producto->stock / (float) $producto->pivot->cantidad));

                        $xlsx->addRow([
                            $paquete->nombre,
                            (float) $paquete->precio,
                            $paquete->descripcion,
                            (bool) $paquete->activo,
                            $unidadesPosibles->isEmpty() ? 0 : (int) $unidadesPosibles->min(),
                            $contenido,
                        ]);
                    }

                    $xlsx->writeTo(fopen('php://output', 'w'));
                }, $nombreXlsx, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ]);
            }

            $nombreArchivo = 'paquetes_' . now()->format('Ymd_His') . '.csv';

            // Sin tope: lazy() trae los paquetes en bloques (con su relación
            // `productos` ya eager-loaded) en vez de cargar todo de golpe.
            return response()->streamDownload(function () use ($query) {
                $salida = fopen('php://output', 'w');

                // BOM UTF-8 para que Excel muestre bien acentos y ñ.
                fwrite($salida, "\xEF\xBB\xBF");

                fputcsv($salida, [
                    'nombre', 'precio', 'descripcion', 'activo', 'stock', 'productos'
                ]);

                foreach ($query->orderBy('nombre')->orderBy('id')->lazy(500) as $paquete) {
                    $contenido = $paquete->productos->map(function ($producto) {
                        $cantidad = (float) ($producto->pivot->cantidad ?? 1);
                        $cantidad = rtrim(rtrim(number_format($cantidad, 2, '.', ''), '0'), '.');
                        return $producto->nombre . ' x' . $cantidad;
                    })->implode(' | ');

                    // Mismo criterio que formatPaqueteResponse(): el stock del
                    // combo lo limita el producto que menos unidades permite.
                    $unidadesPosibles = $paquete->productos
                        ->filter(fn($producto) => (float) ($producto->pivot->cantidad ?? 1) > 0)
                        ->map(fn($producto) => floor((float) $producto->stock / (float) $producto->pivot->cantidad));

                    fputcsv($salida, [
                        $paquete->nombre,
                        number_format((float) $paquete->precio, 2, '.', ''),
                        $paquete->descripcion,
                        $paquete->activo ? 1 : 0,
                        $unidadesPosibles->isEmpty() ? 0 : (int) $unidadesPosibles->min(),
                        $contenido,
                    ]);
                }

                fclose($salida);
            }, $nombreArchivo, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar paquetes',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Importar paquetes desde un array.
     *
     * Las columnas coinciden con las de export(), así que el archivo exportado
     * funciona como plantilla. La columna `productos` ("Nombre x2 | Otro x1") se
     * resuelve por nombre contra el catálogo; la columna `stock` es calculada y
     * se ignora al importar.
     *
     * POST /api/paquetes/import
     */
    public function import(Request $request)
    {
        $request->validate([
            // Sin tope: el export no lo tiene, así que un catálogo completo debe
            // poder reimportarse.
            'paquetes' => 'required|array|min:1',
            'paquetes.*.nombre' => 'required|string|max:255',
            'paquetes.*.precio' => 'required|numeric|min:0',
            'paquetes.*.descripcion' => 'nullable|string',
            'paquetes.*.activo' => 'nullable',
            'paquetes.*.productos' => 'required|string',
            'sobrescribir' => 'nullable|boolean',
            'forzar_precio' => 'nullable|boolean',
        ]);

        try {
            $user = $request->user();

            if (!$user->hasPermission('CREAR_PRODUCTOS')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sin permiso para importar paquetes'
                ], 403);
            }

            $restauranteActivo = app('restaurante_activo');

            // Catálogo del restaurante para resolver los nombres del combo.
            $productosPorNombre = Producto::where('restaurante_id', $restauranteActivo->id)
                ->get(['id', 'nombre'])
                ->mapWithKeys(fn($p) => [mb_strtolower(trim($p->nombre)) => $p->id])
                ->all();

            $sobrescribir = filter_var($request->input('sobrescribir', true), FILTER_VALIDATE_BOOLEAN);
            $forzarPrecio = filter_var($request->input('forzar_precio', false), FILTER_VALIDATE_BOOLEAN);

            // ─────────────────────────────────────────────────────────────
            // Guardia de cambio de precio (igual que en update()): si el paquete
            // está en órdenes sin cobrar, no se aplica nada hasta confirmarlo.
            // ─────────────────────────────────────────────────────────────
            $paquetesBloqueados = [];

            $paquetesExistentesDict = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->get()
                ->mapWithKeys(fn($p) => [mb_strtolower(trim($p->nombre), 'UTF-8') => $p])
                ->all();

            if (!$forzarPrecio && $sobrescribir) {
                foreach ($request->paquetes as $item) {
                    $nombreKey = mb_strtolower(trim($item['nombre']), 'UTF-8');
                    $existente = $paquetesExistentesDict[$nombreKey] ?? null;

                    if (!$existente) {
                        continue;
                    }

                    $cambiosPrecio = $this->cambioDePrecioBase($existente->precio, $item['precio']);

                    if (empty($cambiosPrecio)) {
                        continue;
                    }

                    $ordenesSinCobrar = $this->ordenesSinCobrarConLinea(
                        $existente->restaurante_id,
                        'paquete_id',
                        $existente->id,
                        $this->mapaPreciosNuevos([], $cambiosPrecio)
                    );

                    if (empty($ordenesSinCobrar)) {
                        continue;
                    }

                    $paquetesBloqueados[] = [
                        'item' => [
                            'id' => $existente->id,
                            'nombre' => $existente->nombre,
                            'tipo' => 'paquete'
                        ],
                        'cambios' => $cambiosPrecio,
                        'ordenes' => $ordenesSinCobrar,
                        'impacto' => $this->impactoDeOrdenes($ordenesSinCobrar)
                    ];
                }

                if (!empty($paquetesBloqueados)) {
                    return response()->json([
                        'success' => false,
                        'code' => 'PRECIO_EN_ORDEN_SIN_COBRAR',
                        'message' => 'La importación cambiaría el precio de ' . count($paquetesBloqueados)
                            . ' paquete(s) que están en órdenes sin cobrar. El precio ya capturado en esas '
                            . 'órdenes no cambiará, solo aplicará a órdenes nuevas. ¿Deseas continuar?',
                        'data' => [
                            'items' => $paquetesBloqueados
                        ]
                    ], 409);
                }
            }

            $resultados = [
                'creados' => 0,
                'actualizados' => 0,
                'errores' => []
            ];

            DB::beginTransaction();

            foreach ($request->paquetes as $item) {
                try {
                    $combo = $this->resolverCombo($item['productos'], $productosPorNombre);

                    // Un combo incompleto es peor que no importar la fila.
                    if (!empty($combo['no_encontrados'])) {
                        throw new \Exception('Producto(s) no encontrado(s): ' . implode(', ', $combo['no_encontrados']));
                    }

                    if (empty($combo['productos'])) {
                        throw new \Exception('El paquete no tiene productos');
                    }

                    $nombreItem = trim($item['nombre']);
                    $nombreKey = mb_strtolower($nombreItem, 'UTF-8');
                    $paquete = $paquetesExistentesDict[$nombreKey] ?? null;

                    $atributos = [
                        'nombre' => $item['nombre'],
                        'descripcion' => $item['descripcion'] ?? null,
                        'precio' => $item['precio'],
                        'activo' => array_key_exists('activo', $item)
                            ? filter_var($item['activo'], FILTER_VALIDATE_BOOLEAN)
                            : true,
                    ];

                    if ($paquete) {
                        if (!$sobrescribir) {
                            continue;
                        }

                        $paquete->update($atributos);
                        $paquete->productos()->sync($combo['productos']);
                        $resultados['actualizados']++;
                    } else {
                        $paquete = Paquete::create($atributos + [
                            'restaurante_id' => $restauranteActivo->id,
                            'propietario_id' => $restauranteActivo->propietario_id,
                        ]);
                        $paquete->productos()->attach($combo['productos']);
                        $resultados['creados']++;
                    }

                } catch (\Exception $e) {
                    $resultados['errores'][] = [
                        'paquete' => $item['nombre'] ?? '—',
                        'error' => $e->getMessage()
                    ];
                }
            }

            DB::commit();

            if (method_exists($user, 'logAction')) {
                $user->logAction('IMPORTAR_PAQUETES', 'paquetes', null, "Importación completada: {$resultados['creados']} creados, {$resultados['actualizados']} actualizados. Errores: " . count($resultados['errores']));
            }

            $mensaje = "Importación completada: {$resultados['creados']} creados, {$resultados['actualizados']} actualizados";
            if (count($resultados['errores']) > 0) {
                $mensaje .= ', ' . count($resultados['errores']) . ' errores';
            }

            return response()->json([
                'success' => true,
                'message' => $mensaje,
                'data' => $resultados
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error al importar paquetes',
                'error' => config('app.debug') ? $e->getMessage() : 'Error interno del servidor'
            ], 500);
        }
    }

    /**
     * Convierte la columna `productos` del export ("Taco x2 | Refresco x1") en el
     * arreglo que esperan attach()/sync(): [id => ['cantidad' => n]].
     *
     * Devuelve también los nombres fuera del catálogo para reportarlos como error
     * en lugar de crear un combo incompleto.
     */
    private function resolverCombo($texto, array $productosPorNombre): array
    {
        $productos = [];
        $noEncontrados = [];

        foreach (explode('|', (string) $texto) as $parte) {
            $parte = trim($parte);
            if ($parte === '') {
                continue;
            }

            $cantidad = 1;
            $nombre = $parte;

            // Formato "Nombre x2" (el nombre puede contener espacios; el regex
            // es greedy para quedarse con el último " x<cantidad>").
            if (preg_match('/^(.*)\s+x\s*([0-9]+(?:[.,][0-9]+)?)$/u', $parte, $m)) {
                $nombre = trim($m[1]);
                $cantidad = (float) str_replace(',', '.', $m[2]);
            }

            $clave = mb_strtolower($nombre);

            if (!isset($productosPorNombre[$clave])) {
                $noEncontrados[] = $nombre;
                continue;
            }

            $productos[$productosPorNombre[$clave]] = ['cantidad' => $cantidad > 0 ? $cantidad : 1];
        }

        return [
            'productos' => $productos,
            'no_encontrados' => $noEncontrados
        ];
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric|min:0',
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.1',
            'imagen' => 'nullable|image|max:2048'
        ]);

        try {
            DB::beginTransaction();

            $restauranteActivo = app('restaurante_activo');
            
            $data = $request->only(['nombre', 'descripcion', 'precio']);
            $data['restaurante_id'] = $restauranteActivo->id;
            $data['propietario_id'] = $restauranteActivo->propietario_id;
            $data['activo'] = true;

            if ($request->hasFile('imagen')) {
                $path = $request->file('imagen')->store('paquetes', config('filesystems.images_disk'));
                $data['imagen'] = $path;
            }

            $paquete = Paquete::create($data);

            // Sincronizar productos
            foreach ($request->productos as $prod) {
                $paquete->productos()->attach($prod['id'], ['cantidad' => $prod['cantidad']]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete creado correctamente',
                'data' => $this->formatPaqueteResponse($paquete)
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al crear paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::with(['productos.categoria', 'productos.ingredientes'])
                ->where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json(['success' => true, 'data' => $this->formatPaqueteResponse($paquete)]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Paquete no encontrado'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'nombre' => 'required|string|max:255',
            'descripcion' => 'nullable|string',
            'precio' => 'required|numeric|min:0',
            'productos' => 'required|array|min:1',
            'productos.*.id' => 'required|exists:productos,id',
            'productos.*.cantidad' => 'required|numeric|min:0.1',
            'imagen' => 'nullable|image|max:2048',
            'forzar_precio' => 'nullable|boolean'
        ]);

        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            // ─────────────────────────────────────────────────────────────
            // Guardia de cambio de precio:
            // si el paquete está en alguna orden que todavía no se ha cobrado
            // (cualquier estado distinto de PAGADA/CANCELADA), no se aplica el
            // nuevo precio hasta que el usuario lo confirme con `forzar_precio`.
            // ─────────────────────────────────────────────────────────────
            $cambiosPrecio = $this->cambioDePrecioBase($paquete->precio, $request->input('precio'));
            $forzarPrecio  = filter_var($request->input('forzar_precio', false), FILTER_VALIDATE_BOOLEAN);

            if (!empty($cambiosPrecio) && !$forzarPrecio) {
                $ordenesSinCobrar = $this->ordenesSinCobrarConLinea(
                    $paquete->restaurante_id,
                    'paquete_id',
                    $paquete->id,
                    $this->mapaPreciosNuevos([], $cambiosPrecio)
                );

                if (!empty($ordenesSinCobrar)) {
                    return response()->json([
                        'success' => false,
                        'code' => 'PRECIO_EN_ORDEN_SIN_COBRAR',
                        'message' => 'Este paquete está en ' . count($ordenesSinCobrar)
                            . ' orden(es) sin cobrar. El precio ya capturado en esas órdenes no cambiará, '
                            . 'solo aplicará a órdenes nuevas. ¿Deseas continuar?',
                        'data' => [
                            'item' => [
                                'id' => $paquete->id,
                                'nombre' => $paquete->nombre,
                                'tipo' => 'paquete'
                            ],
                            'cambios' => $cambiosPrecio,
                            'ordenes' => $ordenesSinCobrar,
                            'impacto' => $this->impactoDeOrdenes($ordenesSinCobrar)
                        ]
                    ], 409);
                }
            }

            DB::beginTransaction();

            $data = $request->only(['nombre', 'descripcion', 'precio']);

            if ($request->hasFile('imagen')) {
                // Eliminar imagen anterior
                if ($paquete->imagen) {
                    Storage::disk(config('filesystems.images_disk'))->delete($paquete->imagen);
                }
                $path = $request->file('imagen')->store('paquetes', config('filesystems.images_disk'));
                $data['imagen'] = $path;
            }

            $paquete->update($data);

            // Sincronizar productos
            $productosSinc = [];
            foreach ($request->productos as $prod) {
                $productosSinc[$prod['id']] = ['cantidad' => $prod['cantidad']];
            }
            $paquete->productos()->sync($productosSinc);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paquete actualizado correctamente',
                'data' => $this->formatPaqueteResponse($paquete)
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => 'Error al actualizar paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $ordenesAsociadas = OrdenDetalle::where('paquete_id', $paquete->id)->count();
            if ($ordenesAsociadas > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "No se puede eliminar el paquete porque tiene {$ordenesAsociadas} orden(es) asociada(s)"
                ], 409);
            }

            if ($paquete->imagen) {
                Storage::disk(config('filesystems.images_disk'))->delete($paquete->imagen);
            }

            $paquete->delete();

            return response()->json(['success' => true, 'message' => 'Paquete eliminado correctamente']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al eliminar paquete', 'error' => $e->getMessage()], 500);
        }
    }

    public function toggleActive($id)
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $paquete = Paquete::where('restaurante_id', $restauranteActivo->id)
                ->where('id', $id)
                ->firstOrFail();

            $paquete->update(['activo' => !$paquete->activo]);

            return response()->json([
                'success' => true,
                'message' => 'Estado actualizado',
                'data' => ['activo' => $paquete->activo]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Error al actualizar estado'], 500);
        }
    }
    /**
     * Listar paquetes públicamente (para el Kiosko de Menú)
     */
    public function indexPublic(Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere restaurante_id'
                ], 422);
            }

            $paquetes = Paquete::withoutGlobalScope(\App\Scopes\TenantScope::class)->with(['productos.categoria'])
                ->where('restaurante_id', $restauranteId)
                ->where('activo', true)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $paquetes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener paquetes',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    /**
     * Mostrar un paquete específico públicamente
     */
    public function showPublic($id, Request $request)
    {
        try {
            $restauranteId = $request->get('restaurante_id');
            if (!$restauranteId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Se requiere restaurante_id'
                ], 422);
            }

            $paquete = Paquete::withoutGlobalScope(\App\Scopes\TenantScope::class)->with(['productos.categoria'])
                ->where('restaurante_id', $restauranteId)
                ->where('id', $id)
                ->where('activo', true)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $paquete
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paquete no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener paquete',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // =========================================================================
    // MÉTODOS PRIVADOS
    // =========================================================================

    private function obtenerTotalNominaMensual($restauranteId)
    {
        return User::where('restaurante_activo', $restauranteId)
            ->sum('salario_base');
    }

    private function calcularCostosProducto($producto, $totalNominaMensual, $precioBase = null)
    {
        $costoInsumos = $producto->ingredientes->reduce(function($carry, $ing) {
            $cant = $ing->pivot->cantidad ?? 0;
            return $carry + ($ing->costo_unitario * $cant);
        }, 0);

        $minProd = (float) ($producto->minutos_produccion ?? 0);
        $costoMO = $totalNominaMensual > 0 && $minProd > 0
            ? ($totalNominaMensual / 14400) * 1.36 * $minProd
            : 0;

        $costoBase = $costoInsumos + $costoMO;
        $costoIndirectos = $costoBase * 0.05;
        $costoTotal = $costoBase + $costoIndirectos;

        $precioBase = $precioBase ?? $producto->precio;
        $margenValor = $precioBase - $costoTotal;
        $margenPct = $precioBase > 0 ? round(($margenValor / $precioBase) * 100, 2) : 0;

        return compact('costoInsumos', 'costoMO', 'costoIndirectos', 'costoTotal', 'margenValor', 'margenPct');
    }

    private function formatPaqueteResponse($paquete)
    {
        $paquete->loadMissing(['productos.categoria', 'productos.ingredientes']);

        $totalNominaMensual = $this->obtenerTotalNominaMensual($paquete->restaurante_id);

        $costoTotalPaquete = 0;
        $costoInsumosPaquete = 0;
        $costoMOPaquete = 0;
        $costoIndirectosPaquete = 0;
        $totalMinutosProduccion = 0;

        $unidadesPosibles = collect();
        foreach ($paquete->productos as $producto) {
            $c = $this->calcularCostosProducto($producto, $totalNominaMensual);
            $cantidad = (float) ($producto->pivot->cantidad ?? 1);
            $costoTotalPaquete += $c['costoTotal'] * $cantidad;
            $costoInsumosPaquete += $c['costoInsumos'] * $cantidad;
            $costoMOPaquete += $c['costoMO'] * $cantidad;
            $costoIndirectosPaquete += $c['costoIndirectos'] * $cantidad;
            $totalMinutosProduccion += (float) ($producto->minutos_produccion ?? 0) * $cantidad;

            // Calcular stock posible para este producto del combo
            $stockProducto = (float) $producto->stock;
            if ($cantidad > 0) {
                $unidadesPosibles->push(floor($stockProducto / $cantidad));
            }
        }

        $stockPaquete = $paquete->productos->isEmpty() ? 0 : $unidadesPosibles->min();

        $margenValor = $paquete->precio - $costoTotalPaquete;
        $margenPct = $paquete->precio > 0 ? round(($margenValor / $paquete->precio) * 100, 2) : 0;

        $data = $paquete->toArray();
        $data['costo_insumos'] = round($costoInsumosPaquete, 4);
        $data['costo_mo'] = round($costoMOPaquete, 4);
        $data['costo_indirectos'] = round($costoIndirectosPaquete, 4);
        $data['costo_total'] = round($costoTotalPaquete, 4);
        $data['margen'] = round($margenValor, 2);
        $data['margen_pct'] = $margenPct;
        $data['nomina_mensual_base'] = (float) $totalNominaMensual;
        $data['minutos_produccion'] = $totalMinutosProduccion;
        $data['stock'] = (float) $stockPaquete;
        $data['agotado'] = $stockPaquete <= 0;

        return $data;
    }
}
