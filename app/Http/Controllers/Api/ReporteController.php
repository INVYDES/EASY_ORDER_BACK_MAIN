<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Cliente;
use App\Models\Nomina;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class ReporteController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // VENTAS POR PERÍODO
    // ─────────────────────────────────────────────────────────────────────────

    public function ventasPorPeriodo(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            // ✅ FIX: fecha_inicio y fecha_fin ahora son 'sometimes' con defaults
            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                'grupo'        => 'sometimes|in:dia,semana,mes',
            ]);

            $grupo       = $request->get('grupo', 'dia');
            // ✅ FIX: defaults si no se envían
            $fechaInicio = ($request->fecha_inicio ?? now()->startOfMonth()->format('Y-m-d')) . ' 00:00:00';
            $fechaFin    = ($request->fecha_fin    ?? now()->format('Y-m-d'))                . ' 23:59:59';

            $base = fn () => Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereBetween('created_at', [$fechaInicio, $fechaFin]);

            $ventas = match ($grupo) {
                'dia' => $base()
                    ->select(
                        DB::raw('DATE(created_at) as fecha'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas'),
                        DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
                    )
                    ->groupBy(DB::raw('DATE(created_at)'))
                    ->orderBy(DB::raw('DATE(created_at)'))
                    ->get(),

                'semana' => $base()
                    ->select(
                        DB::raw('YEAR(created_at) as anio'),
                        DB::raw('WEEK(created_at, 1) as semana'),
                        DB::raw('MIN(DATE(created_at)) as fecha'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas'),
                        DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
                    )
                    ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('WEEK(created_at, 1)'))
                    ->orderBy('anio')->orderBy('semana')
                    ->get(),

                'mes' => $base()
                    ->select(
                        DB::raw('YEAR(created_at) as anio'),
                        DB::raw('MONTH(created_at) as mes'),
                        DB::raw('DATE_FORMAT(MIN(created_at), "%Y-%m") as fecha'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas'),
                        DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
                    )
                    ->groupBy(DB::raw('YEAR(created_at)'), DB::raw('MONTH(created_at)'))
                    ->orderBy('anio')->orderBy('mes')
                    ->get(),
            };

            $totales = [
                'total_ordenes'      => $base()->count(),
                'total_ventas'       => (float) ($base()->sum('total') ?? 0),
                'promedio_por_orden' => (float) ($base()->avg('total')  ?? 0),
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'periodo' => [
                        'inicio' => rtrim($fechaInicio, ' 00:00:00'),
                        'fin'    => rtrim($fechaFin,    ' 23:59:59'),
                    ],
                    'ventas'  => $ventas,
                    'totales' => $totales,
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de ventas', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCTOS MÁS VENDIDOS
    // ─────────────────────────────────────────────────────────────────────────

    public function productosMasVendidos(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            // ✅ FIX: validación separada del ValidationException para que el catch
            //         general no lo capture como error 500
            try {
                $request->validate([
                    'limite'       => 'sometimes|integer|min:1|max:500', // ✅ ampliado de 100 a 500
                    'fecha_inicio' => 'sometimes|date',
                    'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                ]);
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['success' => false, 'message' => 'Error de validación', 'errors' => $e->errors()], 422);
            }

            // ✅ FIX: clampear el valor entre 1 y 500 aunque pase validación
            $limite = min(max((int) $request->get('limite', 10), 1), 500);

            $productos = $this->baseDetallesQuery($restauranteActivo->id, $request)
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->select(
                    'productos.id',
                    'productos.nombre',
                    'productos.precio',
                    DB::raw('COALESCE(categorias.nombre, "Sin categoría") as categoria'),
                    DB::raw('CAST(SUM(orden_detalles.cantidad) AS UNSIGNED) as total_vendido'),
                    DB::raw('SUM(orden_detalles.subtotal) as total_ventas'),
                    DB::raw('COUNT(DISTINCT ordenes.id) as veces_vendido'),
                    DB::raw('ROUND(AVG(orden_detalles.precio_unitario), 2) as precio_promedio')
                )
                ->groupBy('productos.id', 'productos.nombre', 'productos.precio', 'categorias.nombre')
                ->orderByDesc('total_vendido')
                ->limit($limite)
                ->get();

            return response()->json(['success' => true, 'data' => $productos]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de productos más vendidos', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALIAS: RENTABILIDAD PRODUCTOS
    // Ruta: GET /api/reportes/rentabilidad-productos
    // ─────────────────────────────────────────────────────────────────────────

    public function rentabilidadProductos(Request $request): JsonResponse
    {
        // Redirige al método principal con los mismos parámetros
        return $this->productosMasVendidos($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCTOS CON MAYOR MARGEN PERO MENOS VENDIDOS
    // ─────────────────────────────────────────────────────────────────────────

    public function productosMayorMargenMenosVendidos(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                'limite'       => 'sometimes|integer|min:1|max:500',
            ]);

            $limite = min(max((int) $request->get('limite', 20), 1), 500);
            $query  = $this->baseDetallesQuery($restauranteActivo->id, $request);

            $top5Ids = (clone $query)
                ->select('productos.id', DB::raw('SUM(orden_detalles.cantidad) as total_vendido'))
                ->groupBy('productos.id')
                ->orderByDesc('total_vendido')
                ->limit(5)
                ->pluck('productos.id')
                ->toArray();

            $productos = (clone $query)
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('COALESCE(categorias.nombre, "Sin categoría") as categoria'),
                    'productos.precio',
                    'productos.minutos_produccion',
                    DB::raw('SUM(orden_detalles.cantidad) as total_vendido'),
                    DB::raw('SUM(orden_detalles.subtotal) as total_ventas')
                )
                ->whereNotIn('productos.id', $top5Ids)
                ->groupBy(
                    'productos.id', 'productos.nombre', 'categorias.nombre',
                    'productos.precio', 'productos.minutos_produccion'
                )
                ->orderByDesc('productos.precio')
                ->limit($limite)
                ->get();

            return response()->json([
                'success' => true,
                'warning' => 'La BD no tiene columna costo. Se muestra precio como referencia de valor.',
                'data'    => $productos,
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de productos menos vendidos', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TIEMPO PROMEDIO DE PREPARACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function tiempoPromedioPreparacion(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                'grupo'        => 'sometimes|in:dia,semana,mes',
            ]);

            $grupo = $request->get('grupo', 'dia');

            $query = DB::table('orden_detalles')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->where('ordenes.restaurante_id', $restauranteActivo->id)
                ->whereIn('orden_detalles.estado_preparacion', ['LISTO', 'ENTREGADO']);

            if ($request->filled('fecha_inicio')) {
                $query->where('orden_detalles.created_at', '>=', $request->fecha_inicio . ' 00:00:00');
            }
            if ($request->filled('fecha_fin')) {
                $query->where('orden_detalles.created_at', '<=', $request->fecha_fin . ' 23:59:59');
            }

            $selectRaw = match ($grupo) {
                'dia'    => 'DATE(orden_detalles.created_at) as periodo',
                'semana' => 'YEARWEEK(orden_detalles.created_at, 1) as periodo',
                'mes'    => 'DATE_FORMAT(orden_detalles.created_at, "%Y-%m") as periodo',
            };

            $tiempos = $query->select(
                DB::raw($selectRaw),
                DB::raw('COALESCE(categorias.nombre, "Sin categoría") as estacion'),
                DB::raw('COUNT(*) as total_items'),
                DB::raw('ROUND(AVG(TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at)), 2) as promedio_minutos'),
                DB::raw('MIN(TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at)) as minimo_minutos'),
                DB::raw('MAX(TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at)) as maximo_minutos')
            )
            ->groupBy('periodo', 'estacion')
            ->orderBy('periodo')
            ->get();

            return response()->json([
                'success' => true,
                'data'    => $tiempos,
                'filtros' => [
                    'grupo'        => $grupo,
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin'    => $request->fecha_fin,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de tiempos de preparación', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCTOS CON RETRASO EN PREPARACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function productosConRetrasoPreparacion(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $query = DB::table('orden_detalles')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->where('ordenes.restaurante_id', $restauranteActivo->id)
                ->whereIn('orden_detalles.estado_preparacion', ['LISTO', 'ENTREGADO'])
                ->whereRaw('TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at) > productos.minutos_produccion')
                ->select(
                    'productos.nombre as producto',
                    'categorias.nombre as estacion',
                    'productos.minutos_produccion as tiempo_estimado',
                    DB::raw('TIMESTAMPDIFF(MINUTE, orden_detalles.created_at, orden_detalles.updated_at) as tiempo_real'),
                    'ordenes.id as orden_id',
                    'orden_detalles.created_at as fecha'
                );

            $hoy    = (clone $query)->whereDate('orden_detalles.created_at', today())->get();
            $semana = (clone $query)->where('orden_detalles.created_at', '>=', now()->subDays(7))->get();
            $mes    = (clone $query)->where('orden_detalles.created_at', '>=', now()->subMonths(1))->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'hoy'            => $hoy,
                    'ultimos_7_dias' => $semana,
                    'ultimo_mes'     => $mes,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de retrasos', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALIAS: TIEMPOS REBASE (tiempos que rebasan el estimado)
    // Ruta: GET /api/reportes/tiempos-rebase
    // ─────────────────────────────────────────────────────────────────────────

    public function tiemposRebase(Request $request): JsonResponse
    {
        return $this->productosConRetrasoPreparacion($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECOMENDACIÓN DE PAQUETE ESTRATÉGICO
    // ─────────────────────────────────────────────────────────────────────────

    public function recomendacionPaquete(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $categoriasCocina = ['cocina'];
            $categoriasBebida = ['barra'];
            $categoriasPostre = ['postres'];

            $query = $this->baseDetallesQuery($restauranteActivo->id, $request)
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id');

            $top10Ids = (clone $query)
                ->select('productos.id', DB::raw('SUM(orden_detalles.cantidad) as total_vendido'))
                ->groupBy('productos.id')
                ->orderByDesc('total_vendido')
                ->limit(10)
                ->pluck('productos.id')
                ->toArray();

            $camposProducto = [
                'productos.id',
                'productos.nombre',
                DB::raw('COALESCE(categorias.nombre, "Sin categoría") as categoria'),
                'productos.precio',
                DB::raw('SUM(orden_detalles.cantidad) as total_vendido'),
            ];

            $groupByBase = ['productos.id', 'productos.nombre', 'categorias.nombre', 'productos.precio'];

            $platillo = (clone $query)
                ->select($camposProducto)
                ->whereIn(DB::raw('LOWER(COALESCE(categorias.nombre, ""))'), $categoriasCocina)
                ->whereNotIn('productos.id', $top10Ids)
                ->groupBy($groupByBase)
                ->orderByDesc('productos.precio')
                ->first();

            $bebida = (clone $query)
                ->select($camposProducto)
                ->whereIn(DB::raw('LOWER(COALESCE(categorias.nombre, ""))'), $categoriasBebida)
                ->groupBy($groupByBase)
                ->orderByDesc('total_vendido')
                ->first();

            $postre = (clone $query)
                ->select($camposProducto)
                ->whereIn(DB::raw('LOWER(COALESCE(categorias.nombre, ""))'), $categoriasPostre)
                ->groupBy($groupByBase)
                ->having('total_vendido', '>', 0)
                ->orderBy('total_vendido')
                ->first();

            $precioSuma     = ($platillo->precio ?? 0) + ($bebida->precio ?? 0) + ($postre->precio ?? 0);
            $precioSugerido = round($precioSuma * 0.90, 2);

            $justificacion = sprintf(
                'Combinar "%s" (mayor precio fuera del top 10) con "%s" (bebida #1 en ventas) y "%s" '
                . '(postre de menor rotación). Precio sugerido $%s con 10%% de descuento sobre suma individual ($%s).',
                $platillo->nombre ?? 'N/D',
                $bebida->nombre   ?? 'N/D',
                $postre->nombre   ?? 'N/D',
                number_format($precioSugerido, 2),
                number_format($precioSuma, 2)
            );

            return response()->json([
                'success' => true,
                'data'    => [
                    'paquete' => [
                        'platillo_cocina'      => $platillo,
                        'bebida_top'           => $bebida,
                        'postre_menos_vendido' => $postre,
                    ],
                    'precio_individual_suma'  => round($precioSuma, 2),
                    'precio_sugerido_paquete' => $precioSugerido,
                    'descuento_aplicado_pct'  => 10,
                    'justificacion'           => $justificacion,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar recomendación de paquete', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VENTAS POR CANAL
    // ─────────────────────────────────────────────────────────────────────────

    public function ventasPorCanal(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                'grupo'        => 'sometimes|in:dia,semana,mes',
            ]);

            $grupo = $request->get('grupo', 'dia');

            $query = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA');

            if ($request->filled('fecha_inicio')) {
                $query->where('created_at', '>=', $request->fecha_inicio . ' 00:00:00');
            }
            if ($request->filled('fecha_fin')) {
                $query->where('created_at', '<=', $request->fecha_fin . ' 23:59:59');
            }

            $selectRaw = match ($grupo) {
                'dia'    => 'DATE(created_at) as periodo',
                'semana' => 'YEARWEEK(created_at, 1) as periodo',
                'mes'    => 'DATE_FORMAT(created_at, "%Y-%m") as periodo',
            };

            $canales = (clone $query)
                ->select(
                    DB::raw($selectRaw),
                    DB::raw('COALESCE(metodo_pago, "Sin especificar") as canal'),
                    DB::raw('COUNT(*) as total_ordenes'),
                    DB::raw('SUM(total) as total_ventas'),
                    DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
                )
                ->groupBy('periodo', 'metodo_pago')
                ->orderBy('periodo')
                ->get();

            $totalVentas = (float) ((clone $query)->sum('total') ?: 1);

            $canales = $canales->map(function ($row) use ($totalVentas) {
                $row->porcentaje_ventas = $totalVentas > 0
                    ? round(($row->total_ventas / $totalVentas) * 100, 2)
                    : 0;
                return $row;
            });

            return response()->json([
                'success' => true,
                'data'    => [
                    'canales' => $canales,
                    'totales' => [
                        'total_ordenes' => (clone $query)->count(),
                        'total_ventas'  => round($totalVentas, 2),
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte por canal', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INVERSIÓN Y UTILIDAD
    // ─────────────────────────────────────────────────────────────────────────

    public function inversionYUtilidad(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $queryOrdenes = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA');

            if ($request->filled('fecha_inicio')) {
                $queryOrdenes->where('created_at', '>=', $request->fecha_inicio . ' 00:00:00');
            }
            if ($request->filled('fecha_fin')) {
                $queryOrdenes->where('created_at', '<=', $request->fecha_fin . ' 23:59:59');
            }

            $totalVentas       = (float) ($queryOrdenes->sum('total') ?? 0);
            $inversionProducto = 0.0;

            $inversionManoObra = 0.0;
            if (Schema::hasTable('nomina_diaria')) {
                $qNomina = DB::table('nomina_diaria')
                    ->where('restaurante_id', $restauranteActivo->id);
                if ($request->filled('fecha_inicio')) $qNomina->where('fecha', '>=', $request->fecha_inicio);
                if ($request->filled('fecha_fin'))    $qNomina->where('fecha', '<=', $request->fecha_fin);
                $inversionManoObra = (float) ($qNomina->sum('total_mano_obra') ?? 0);
            }

            $utilidadBruta = $totalVentas - $inversionProducto;
            $utilidadNeta  = $utilidadBruta - $inversionManoObra;

            return response()->json([
                'success' => true,
                'warning' => 'La BD no tiene columna costo en productos; inversión_producto = 0.',
                'data'    => [
                    'total_ventas'        => round($totalVentas, 2),
                    'inversion_producto'  => round($inversionProducto, 2),
                    'inversion_mano_obra' => round($inversionManoObra, 2),
                    'utilidad_bruta'      => round($utilidadBruta, 2),
                    'utilidad_neta'       => round($utilidadNeta, 2),
                    'margen_bruto_pct'    => $totalVentas > 0 ? round(($utilidadBruta / $totalVentas) * 100, 2) : 0,
                    'margen_neto_pct'     => $totalVentas > 0 ? round(($utilidadNeta  / $totalVentas) * 100, 2) : 0,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al calcular inversión y utilidad', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PROPINAS
    // ─────────────────────────────────────────────────────────────────────────

    public function totalPropinas(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $query = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA');

            if ($request->filled('fecha_inicio')) {
                $query->where('created_at', '>=', $request->fecha_inicio . ' 00:00:00');
            }
            if ($request->filled('fecha_fin')) {
                $query->where('created_at', '<=', $request->fecha_fin . ' 23:59:59');
            }

            $propinas = (clone $query)->select(
                DB::raw('COUNT(*) as total_ordenes'),
                DB::raw('COALESCE(SUM(propina), 0) as total_propinas'),
                DB::raw('ROUND(AVG(NULLIF(propina, 0)), 2) as promedio_propina'),
                DB::raw('COUNT(CASE WHEN propina > 0 THEN 1 END) as ordenes_con_propina'),
                DB::raw('ROUND(COUNT(CASE WHEN propina > 0 THEN 1 END) / NULLIF(COUNT(*), 0) * 100, 2) as porcentaje_ordenes_con_propina')
            )->first();

            return response()->json([
                'success' => true,
                'note'    => 'La BD tiene propina única (no desglosada por terminal/transferencia).',
                'data'    => $propinas,
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al calcular propinas', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UTILIDAD DEL DÍA ACUMULADA
    // ─────────────────────────────────────────────────────────────────────────

    public function utilidadDiaAcumulada(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha' => 'sometimes|date',
            ]);

            $fecha = $request->get('fecha', today()->format('Y-m-d'));

            $ventasDia = (float) Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereDate('created_at', $fecha)
                ->sum('total');

            $costoProducto = 0.0;

            $propinasDia = (float) Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereDate('created_at', $fecha)
                ->sum('propina');

            $manoObraDia = 0.0;
            if (Schema::hasTable('nomina_diaria')) {
                $manoObraDia = (float) DB::table('nomina_diaria')
                    ->where('restaurante_id', $restauranteActivo->id)
                    ->whereDate('fecha', $fecha)
                    ->sum('total_mano_obra');
            }

            $ordenesEnProceso = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereNotIn('estado', ['CERRADA', 'CANCELADA'])
                ->whereDate('created_at', $fecha)
                ->count();

            $utilidadBruta = $ventasDia - $costoProducto;
            $utilidadNeta  = $utilidadBruta - $manoObraDia;

            return response()->json([
                'success' => true,
                'data'    => [
                    'fecha'              => $fecha,
                    'ventas_dia'         => round($ventasDia, 2),
                    'costo_producto_dia' => round($costoProducto, 2),
                    'propinas_dia'       => round($propinasDia, 2),
                    'mano_obra_dia'      => round($manoObraDia, 2),
                    'utilidad_bruta_dia' => round($utilidadBruta, 2),
                    'utilidad_neta_dia'  => round($utilidadNeta, 2),
                    'margen_bruto_pct'   => $ventasDia > 0 ? round(($utilidadBruta / $ventasDia) * 100, 2) : 0,
                    'margen_neto_pct'    => $ventasDia > 0 ? round(($utilidadNeta  / $ventasDia) * 100, 2) : 0,
                    'ordenes_en_proceso' => $ordenesEnProceso,
                    'hora_calculo'       => now()->format('H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al calcular utilidad del día', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ALIAS: FINANZAS DÍA
    // Ruta: GET /api/reportes/finanzas-dia
    // ─────────────────────────────────────────────────────────────────────────

    public function finanzasDia(Request $request): JsonResponse
    {
        return $this->utilidadDiaAcumulada($request);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DASHBOARD
    // ─────────────────────────────────────────────────────────────────────────

    public function dashboard(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');
            $hoy = today()->format('Y-m-d');

            $queryHoy = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereDate('created_at', $hoy);

            $ventasHoy      = (float) ((clone $queryHoy)->sum('total') ?? 0);
            $ordenesHoy     = (clone $queryHoy)->count();
            $ticketPromedio = $ordenesHoy > 0 ? round($ventasHoy / $ordenesHoy, 2) : 0;

            $ordenesPorEstado = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)
                ->select('estado', DB::raw('COUNT(*) as total'))
                ->groupBy('estado')
                ->get();

            $totalOrdenesHoy = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)->count();
            $canceladasHoy   = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)->where('estado', 'CANCELADA')->count();
            $tasaCancelacion = $totalOrdenesHoy > 0
                ? round(($canceladasHoy / $totalOrdenesHoy) * 100, 2) : 0;

            $ventasPorMetodo = (clone $queryHoy)
                ->select(
                    DB::raw('COALESCE(metodo_pago, "Sin especificar") as metodo_pago'),
                    DB::raw('COUNT(*) as total_ordenes'),
                    DB::raw('SUM(total) as total_ventas')
                )
                ->groupBy('metodo_pago')
                ->get();

            $propinaHoy  = (float) ((clone $queryHoy)->sum('propina') ?? 0);
            $manoObraHoy = 0.0;

            if (Schema::hasTable('nomina_diaria')) {
                $manoObraHoy = (float) DB::table('nomina_diaria')
                    ->where('restaurante_id', $restauranteActivo->id)
                    ->whereDate('fecha', $hoy)
                    ->sum('total_mano_obra');
            }

            $utilidadBrutaHoy = $ventasHoy;
            $utilidadNetaHoy  = $utilidadBrutaHoy - $manoObraHoy;

            $topClientes = [];
            if (class_exists('App\Models\Cliente')) {
                $topClientes = Cliente::where('restaurante_id', $restauranteActivo->id)
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get(['id', 'nombre', 'email', 'telefono']);
            }

            $productosBajoStock = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->whereNotNull('stock')
                ->whereNotNull('stock_minimo')
                ->whereColumn('stock', '<=', 'stock_minimo')
                ->orderBy('stock')
                ->limit(10)
                ->get(['id', 'nombre', 'stock', 'stock_minimo']);

            $ordenesPorHora = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', $hoy)
                ->select(
                    DB::raw('HOUR(created_at) as hora'),
                    DB::raw('COUNT(*) as total_ordenes'),
                    DB::raw('SUM(total) as total_ventas')
                )
                ->groupBy('hora')
                ->orderBy('hora')
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'fecha'                => $hoy,
                    'hora_calculo'         => now()->format('H:i:s'),
                    'ventas_hoy'           => round($ventasHoy, 2),
                    'ordenes_hoy'          => $ordenesHoy,
                    'ticket_promedio'      => $ticketPromedio,
                    'tasa_cancelacion_pct' => $tasaCancelacion,
                    'ordenes_por_estado'   => $ordenesPorEstado,
                    'ventas_por_metodo'    => $ventasPorMetodo,
                    'propinas_hoy'         => round($propinaHoy, 2),
                    'inversion_mano_obra'  => round($manoObraHoy, 2),
                    'utilidad_bruta_hoy'   => round($utilidadBrutaHoy, 2),
                    'utilidad_neta_hoy'    => round($utilidadNetaHoy, 2),
                    'margen_bruto_pct'     => $ventasHoy > 0 ? round(($utilidadBrutaHoy / $ventasHoy) * 100, 2) : 0,
                    'margen_neto_pct'      => $ventasHoy > 0 ? round(($utilidadNetaHoy  / $ventasHoy) * 100, 2) : 0,
                    'ordenes_por_hora'     => $ordenesPorHora,
                    'top_clientes'         => $topClientes,
                    'productos_bajo_stock' => $productosBajoStock,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al obtener dashboard', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CLIENTES FRECUENTES
    // ─────────────────────────────────────────────────────────────────────────

    public function clientesFrecuentes(Request $request): JsonResponse
    {
        try {
            if (!class_exists('App\Models\Cliente')) {
                return response()->json(['success' => false, 'message' => 'Módulo de clientes no instalado.'], 404);
            }

            $restauranteActivo = app('restaurante_activo');
            $limite = (int) $request->get('limite', 10);

            $clientes = DB::table('clientes')
                ->where('clientes.restaurante_id', $restauranteActivo->id)
                ->leftJoin('ordenes', function ($join) {
                    $join->on('ordenes.cliente_id', '=', 'clientes.id')
                         ->where('ordenes.estado', 'CERRADA');
                })
                ->select(
                    'clientes.id',
                    'clientes.nombre',
                    'clientes.email',
                    'clientes.telefono',
                    DB::raw('COUNT(ordenes.id) as total_compras'),
                    DB::raw('COALESCE(SUM(ordenes.total), 0) as gasto_total')
                )
                ->groupBy('clientes.id', 'clientes.nombre', 'clientes.email', 'clientes.telefono')
                ->orderByDesc('total_compras')
                ->limit($limite)
                ->get();

            return response()->json(['success' => true, 'data' => $clientes]);

        } catch (\Exception $e) {
            return $this->error('Error al obtener clientes frecuentes', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REPORTE FINANCIERO
    // ─────────────────────────────────────────────────────────────────────────

    public function reporteFinanciero(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->format('Y-m-d'));
            $fechaFin    = $request->get('fecha_fin', now()->format('Y-m-d'));

            $ventas = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('total');

            $gastos = DB::table('gastos')
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('monto');

            $nominas = 0;
            if (Schema::hasTable('nomina_diaria')) {
                $nominas = DB::table('nomina_diaria')
                    ->where('restaurante_id', $restauranteActivo->id)
                    ->whereBetween('fecha', [$fechaInicio, $fechaFin])
                    ->sum('total_mano_obra');
            }

            $ganancia = $ventas - ($gastos + $nominas);

            return response()->json([
                'success' => true,
                'data'    => [
                    'ventas_totales'  => round($ventas, 2),
                    'gastos_totales'  => round($gastos, 2),
                    'nominas_totales' => round($nominas, 2),
                    'ganancia_neta'   => round($ganancia, 2),
                    'periodo'         => [
                        'inicio' => $fechaInicio,
                        'fin'    => $fechaFin,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte financiero', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // REPORTE PRODUCTOS
    // ─────────────────────────────────────────────────────────────────────────

    public function reporteProductos(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $productos = $this->baseDetallesQuery($restauranteActivo->id, $request)
                ->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('SUM(orden_detalles.cantidad) as total_vendidos'),
                    DB::raw('SUM(orden_detalles.subtotal) as ingresos_totales'),
                    DB::raw('ROUND(AVG(orden_detalles.precio_unitario), 2) as precio_promedio')
                )
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('total_vendidos')
                ->get();

            return response()->json(['success' => true, 'data' => $productos]);

        } catch (\Exception $e) {
            return $this->error('Error al generar reporte de productos', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // EXPORTAR
    // ─────────────────────────────────────────────────────────────────────────

    public function exportar(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'tipo'         => 'required|in:ventas,productos,clientes,utilidad,propinas,canales,paquete,retrasos,tiempos,roi',
                'formato'      => 'required|in:pdf,excel,csv',
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->get('fecha_inicio', now()->subDays(30)->format('Y-m-d'));
            $fechaFin    = $request->get('fecha_fin',    now()->format('Y-m-d'));

            return response()->json([
                'success' => true,
                'message' => "Reporte de {$request->tipo} en formato {$request->formato} generado correctamente.",
                'data'    => [
                    'url'     => url("/api/reportes/download/{$request->tipo}/{$request->formato}")
                                 . "?fecha_inicio={$fechaInicio}&fecha_fin={$fechaFin}",
                    'tipo'    => $request->tipo,
                    'formato' => $request->formato,
                    'filtros' => ['desde' => $fechaInicio, 'hasta' => $fechaFin],
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['success' => false, 'message' => 'Datos inválidos.', 'errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return $this->error('Error al exportar reporte', $e);
        }
    }

    // =========================================================================
    // ROI — CONFIGURACIÓN
    // =========================================================================

    public function roiObtenerConfig(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $config = \App\Models\RoiConfig::firstOrCreate(
                ['restaurante_id' => $restauranteActivo->id],
                [
                    'inversion_inicial' => 0,
                    'utilidad_objetivo' => 0,
                    'gasto_renta'       => 0,
                    'gasto_servicios'   => 0,
                    'gasto_software'    => 0,
                    'gasto_marketing'   => 0,
                ]
            );

            return response()->json(['success' => true, 'data' => $config]);

        } catch (\Exception $e) {
            return $this->error('Error al obtener configuración ROI', $e);
        }
    }

    public function roiGuardarConfig(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'inversion_inicial' => 'sometimes|numeric|min:0',
                'utilidad_objetivo' => 'sometimes|numeric|min:0',
                'gasto_renta'       => 'sometimes|numeric|min:0',
                'gasto_servicios'   => 'sometimes|numeric|min:0',
                'gasto_software'    => 'sometimes|numeric|min:0',
                'gasto_marketing'   => 'sometimes|numeric|min:0',
            ]);

            $config = \App\Models\RoiConfig::updateOrCreate(
                ['restaurante_id' => $restauranteActivo->id],
                $request->only([
                    'inversion_inicial',
                    'utilidad_objetivo',
                    'gasto_renta',
                    'gasto_servicios',
                    'gasto_software',
                    'gasto_marketing',
                ])
            );

            return response()->json(['success' => true, 'data' => $config]);

        } catch (\Exception $e) {
            return $this->error('Error al guardar configuración ROI', $e);
        }
    }

    // =========================================================================
    // ROI — CÁLCULO COMPLETO
    // =========================================================================

    public function roiCompleto(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $fechaInicio = $request->get('fecha_inicio', now()->startOfMonth()->format('Y-m-d'));
            $fechaFin    = $request->get('fecha_fin',    now()->format('Y-m-d'));

            $config = \App\Models\RoiConfig::firstOrCreate(
                ['restaurante_id' => $restauranteActivo->id],
                [
                    'inversion_inicial' => 0, 'utilidad_objetivo' => 0,
                    'gasto_renta' => 0, 'gasto_servicios' => 0,
                    'gasto_software' => 0, 'gasto_marketing' => 0,
                ]
            );

            $inversionInicial = (float) $config->inversion_inicial;
            $utilidadObjetivo = (float) $config->utilidad_objetivo;

            $ventasMes = (float) Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('total');

            $gastosVariables = (float) DB::table('gastos')
                ->where('restaurante_id', $restauranteActivo->id)
                ->whereBetween('created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->sum('monto');

            $nominaMes = (float) Nomina::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'PAGADA')
                ->whereBetween('periodo_fin', [$fechaInicio, $fechaFin])
                ->sum('pago_total');

            $gastosOperativos = round(
                (float) $config->gasto_renta    +
                (float) $config->gasto_servicios +
                (float) $config->gasto_software  +
                (float) $config->gasto_marketing +
                $nominaMes,
                2
            );

            $gananciaNeta = round($ventasMes - $gastosVariables - $gastosOperativos, 2);

            $margenContribucion = $ventasMes > 0
                ? round(1 - ($gastosVariables / $ventasMes), 4)
                : 0;

            $puntoEquilibrio = $margenContribucion > 0
                ? round($gastosOperativos / $margenContribucion, 2)
                : null;

            $pctCumplimientoPE = ($puntoEquilibrio && $puntoEquilibrio > 0 && $ventasMes > 0)
                ? round(($ventasMes / $puntoEquilibrio) * 100, 2)
                : null;

            $roiGeneral = $inversionInicial > 0
                ? round(($gananciaNeta / $inversionInicial) * 100, 2)
                : null;

            $pctUtilidad = $ventasMes > 0
                ? round(($gananciaNeta / $ventasMes) * 100, 2)
                : 0;

            $pctCumplimientoObjetivo = $utilidadObjetivo > 0
                ? round(($gananciaNeta / $utilidadObjetivo) * 100, 2)
                : null;

            $semaforo = match (true) {
                $roiGeneral === null => 'sin_datos',
                $roiGeneral < 5      => 'rojo',
                $roiGeneral <= 15    => 'amarillo',
                default              => 'verde',
            };

            $roiProductos = DB::table('orden_detalles')
                ->join('ordenes',   'orden_detalles.orden_id',    '=', 'ordenes.id')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->leftJoin('categorias', 'productos.categoria_id', '=', 'categorias.id')
                ->where('ordenes.restaurante_id', $restauranteActivo->id)
                ->where('ordenes.estado', 'CERRADA')
                ->whereBetween('ordenes.created_at', [$fechaInicio . ' 00:00:00', $fechaFin . ' 23:59:59'])
                ->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('COALESCE(categorias.nombre, "Sin categoría") as categoria'),
                    'productos.precio',
                    DB::raw('SUM(orden_detalles.cantidad) as unidades_vendidas'),
                    DB::raw('SUM(orden_detalles.subtotal) as ingreso_total'),
                    DB::raw('NULL as roi_producto')
                )
                ->groupBy('productos.id', 'productos.nombre', 'categorias.nombre', 'productos.precio')
                ->orderByDesc('ingreso_total')
                ->limit(10)
                ->get();

            return response()->json([
                'success' => true,
                'data'    => [
                    'periodo' => [
                        'inicio' => $fechaInicio,
                        'fin'    => $fechaFin,
                    ],
                    'config' => [
                        'inversion_inicial' => $inversionInicial,
                        'utilidad_objetivo' => $utilidadObjetivo,
                        'gasto_renta'       => (float) $config->gasto_renta,
                        'gasto_servicios'   => (float) $config->gasto_servicios,
                        'gasto_software'    => (float) $config->gasto_software,
                        'gasto_marketing'   => (float) $config->gasto_marketing,
                    ],
                    'financiero' => [
                        'venta_mes'         => round($ventasMes, 2),
                        'gastos_variables'  => round($gastosVariables, 2),
                        'nomina_mes'        => round($nominaMes, 2),
                        'gastos_operativos' => round($gastosOperativos, 2),
                        'ganancia_neta'     => $gananciaNeta,
                    ],
                    'kpis' => [
                        'utilidad_objetivo'       => $utilidadObjetivo,
                        'utilidad_real'           => $gananciaNeta,
                        'pct_cumplimiento_obj'    => $pctCumplimientoObjetivo,
                        'roi_general'             => $roiGeneral,
                        'semaforo'                => $semaforo,
                        'margen_contribucion'     => $margenContribucion,
                        'punto_equilibrio'        => $puntoEquilibrio,
                        'pct_cumplimiento_pe'     => $pctCumplimientoPE,
                        'pct_utilidad'            => $pctUtilidad,
                    ],
                    'roi_por_producto' => $roiProductos,
                    'nota' => 'roi_producto = null: agrega columna `costo` a productos para habilitarlo.',
                ],
            ]);

        } catch (\Exception $e) {
            return $this->error('Error al calcular ROI', $e);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────────────────────────────────

    private function baseDetallesQuery(int $restauranteId, Request $request): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('orden_detalles')
            ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
            ->join('ordenes',   'orden_detalles.orden_id',    '=', 'ordenes.id')
            ->where('ordenes.restaurante_id', $restauranteId)
            ->where('ordenes.estado', 'CERRADA');

        if ($request->filled('fecha_inicio')) {
            $query->where('ordenes.created_at', '>=', $request->fecha_inicio . ' 00:00:00');
        }
        if ($request->filled('fecha_fin')) {
            $query->where('ordenes.created_at', '<=', $request->fecha_fin . ' 23:59:59');
        }

        return $query;
    }

    private function error(string $mensaje, \Exception $e): JsonResponse
    {
        $payload = ['success' => false, 'message' => $mensaje];

        if (config('app.debug')) {
            $payload['error'] = $e->getMessage();
            $payload['trace'] = collect($e->getTrace())->take(5)->toArray();
        }

        return response()->json($payload, 500);
    }
    /**
 * Ventas por canal (Local, Pickup, Delivery) - Gráfica Donut
 * GET /api/reportes/ventas-por-canal-tipo
 */
public function ventasPorCanalTipo(Request $request): JsonResponse
{
    try {
        $restauranteActivo = app('restaurante_activo');

        $request->validate([
            'fecha_inicio' => 'sometimes|date',
            'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
        ]);

        $query = Orden::where('restaurante_id', $restauranteActivo->id)
            ->where('estado', 'CERRADA');

        if ($request->filled('fecha_inicio')) {
            $query->whereDate('created_at', '>=', $request->fecha_inicio);
        }
        if ($request->filled('fecha_fin')) {
            $query->whereDate('created_at', '<=', $request->fecha_fin);
        }

        $ventasPorTipo = (clone $query)
            ->select(
                'tipo_orden',
                DB::raw('COUNT(*) as total_ordenes'),
                DB::raw('COALESCE(SUM(total), 0) as total_ventas'),
                DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
            )
            ->groupBy('tipo_orden')
            ->get();

        $totalVentas = (clone $query)->sum('total');
        $totalOrdenes = (clone $query)->count();

        // Preparar datos para gráfica donut
        $labels = [];
        $datasets = [];
        $colors = [
            'local' => '#3B82F6',
            'pickup' => '#10B981',
            'delivery' => '#F59E0B',
        ];
        $icons = [
            'local' => '🏠',
            'pickup' => '📦',
            'delivery' => '🛵',
        ];
        $textos = [
            'local' => 'Comer en local',
            'pickup' => 'Para llevar',
            'delivery' => 'Delivery',
        ];

        foreach ($ventasPorTipo as $item) {
            $labels[] = $textos[$item->tipo_orden] ?? $item->tipo_orden;
            $datasets[] = [
                'label' => $textos[$item->tipo_orden] ?? $item->tipo_orden,
                'value' => round($item->total_ventas, 2),
                'percentage' => $totalVentas > 0 ? round(($item->total_ventas / $totalVentas) * 100, 2) : 0,
                'color' => $colors[$item->tipo_orden] ?? '#6B7280',
                'icon' => $icons[$item->tipo_orden] ?? '📋',
                'ordenes' => (int) $item->total_ordenes,
                'ticket_promedio' => (float) $item->ticket_promedio,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'grafica_donut' => [
                    'labels' => $labels,
                    'datasets' => $datasets,
                ],
                'resumen' => [
                    'total_ventas' => round($totalVentas, 2),
                    'total_ordenes' => $totalOrdenes,
                    'ticket_promedio_general' => $totalOrdenes > 0 ? round($totalVentas / $totalOrdenes, 2) : 0,
                ],
                'periodo' => [
                    'desde' => $request->fecha_inicio,
                    'hasta' => $request->fecha_fin,
                ],
            ],
        ]);

    } catch (\Exception $e) {
        return $this->error('Error al obtener ventas por canal', $e);
    }
}
}