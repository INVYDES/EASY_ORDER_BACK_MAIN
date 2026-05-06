<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Cliente;
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

            $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
                'grupo'        => 'sometimes|in:dia,semana,mes',
            ]);

            $grupo       = $request->get('grupo', 'dia');
            $fechaInicio = $request->fecha_inicio . ' 00:00:00';
            $fechaFin    = $request->fecha_fin    . ' 23:59:59';

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
                        'inicio' => $request->fecha_inicio,
                        'fin'    => $request->fecha_fin,
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

            $request->validate([
                'limite'       => 'sometimes|integer|min:1|max:100',
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            $limite = $request->get('limite', 10);

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
    // PRODUCTOS CON MAYOR MARGEN PERO MENOS VENDIDOS
    // NOTA: productos no tiene columna `costo` en la BD real.
    //       Se usa minutos_produccion * nomina_diaria como aproximación de costo
    //       si están disponibles; si no, margen = 0.
    // ─────────────────────────────────────────────────────────────────────────

    public function productosMayorMargenMenosVendidos(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
                'limite'       => 'sometimes|integer|min:1|max:100',
            ]);

            $limite = $request->get('limite', 20);
            $query  = $this->baseDetallesQuery($restauranteActivo->id, $request);

            $top5Ids = (clone $query)
                ->select('productos.id', DB::raw('SUM(orden_detalles.cantidad) as total_vendido'))
                ->groupBy('productos.id')
                ->orderByDesc('total_vendido')
                ->limit(5)
                ->pluck('productos.id')
                ->toArray();

            // Costo estimado = (minutos_produccion / 60) * (nomina_diaria / horas_dia)
            // Como aproximación simple usamos precio como referencia de "margen"
            // ya que la BD no tiene columna costo.
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
    // NOTA: orden_detalles no tiene tiempo_inicio_prep / tiempo_fin_prep / tipo_estacion.
    // ─────────────────────────────────────────────────────────────────────────

    public function tiempoPromedioPreparacion(Request $request): JsonResponse
    {
        // Columnas no existen en el esquema real; se informa adecuadamente.
        return response()->json([
            'success' => true,
            'warning' => 'Las columnas de tiempo de preparación (tiempo_inicio_prep, tiempo_fin_prep, tipo_estacion) no existen en esta instalación.',
            'data'    => array_fill_keys(['cocina', 'barra', 'postres'], [
                'total_items'      => 0,
                'promedio_minutos' => null,
                'minimo_minutos'   => null,
                'maximo_minutos'   => null,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRODUCTOS CON RETRASO EN PREPARACIÓN
    // ─────────────────────────────────────────────────────────────────────────

    public function productosConRetrasoPreparacion(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'warning' => 'Las columnas de tiempo de preparación no existen en esta instalación.',
            'data'    => ['hoy' => [], 'ultimos_7_dias' => [], 'ultimo_mes' => []],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // RECOMENDACIÓN DE PAQUETE ESTRATÉGICO
    // Ajustado: usa categorias.nombre para filtrar, precio en lugar de precio_venta.
    // ─────────────────────────────────────────────────────────────────────────

    public function recomendacionPaquete(Request $request): JsonResponse
    {
        try {
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin'    => 'sometimes|date|after_or_equal:fecha_inicio',
            ]);

            // Nombres de categorías según datos reales en la BD
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

            // Platillo: mayor precio fuera del top 10 (proxy de mayor margen)
            $platillo = (clone $query)
                ->select($camposProducto)
                ->whereIn(DB::raw('LOWER(COALESCE(categorias.nombre, ""))'), $categoriasCocina)
                ->whereNotIn('productos.id', $top10Ids)
                ->groupBy($groupByBase)
                ->orderByDesc('productos.precio')
                ->first();

            // Bebida: #1 en ventas
            $bebida = (clone $query)
                ->select($camposProducto)
                ->whereIn(DB::raw('LOWER(COALESCE(categorias.nombre, ""))'), $categoriasBebida)
                ->groupBy($groupByBase)
                ->orderByDesc('total_vendido')
                ->first();

            // Postre: menos vendido (con al menos 1 venta)
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
    // NOTA: ordenes no tiene columna `canal` en el esquema real.
    //       Se agrupa por metodo_pago como sustituto.
    // ─────────────────────────────────────────────────────────────────────────

    public function ventasPorCanal(Request $request): JsonResponse
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

            $totalVentas = (float) ((clone $query)->sum('total') ?: 1);

            // Usamos metodo_pago como proxy de canal (efectivo / tarjeta / transferencia)
            $canales = (clone $query)
                ->select(
                    DB::raw('COALESCE(metodo_pago, "Sin especificar") as canal'),
                    DB::raw('COUNT(*) as total_ordenes'),
                    DB::raw('SUM(total) as total_ventas'),
                    DB::raw('ROUND(AVG(total), 2) as ticket_promedio')
                )
                ->groupBy('metodo_pago')
                ->orderByDesc('total_ventas')
                ->get()
                ->map(function ($row) use ($totalVentas) {
                    $row->porcentaje_ventas = round(($row->total_ventas / $totalVentas) * 100, 2);
                    return $row;
                });

            return response()->json([
                'success' => true,
                'warning' => 'La BD no tiene columna canal; se usa metodo_pago como agrupador.',
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
    // NOTA: productos no tiene columna costo → inversión en producto = 0.
    //       Se retorna igualmente la estructura para no romper clientes.
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

            $totalVentas = (float) ($queryOrdenes->sum('total') ?? 0);

            // Sin columna costo en productos, inversión = 0
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
    // NOTA: ordenes tiene `propina` (campo único), NO propina_terminal / propina_transferencia.
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

            // Sin columna costo, inversión producto = 0
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

            // Agrupado por metodo_pago (proxy de canal)
            $ventasPorMetodo = (clone $queryHoy)
                ->select(
                    DB::raw('COALESCE(metodo_pago, "Sin especificar") as metodo_pago'),
                    DB::raw('COUNT(*) as total_ordenes'),
                    DB::raw('SUM(total) as total_ventas')
                )
                ->groupBy('metodo_pago')
                ->get();

            // Propina única
            $propinaHoy = (float) ((clone $queryHoy)->sum('propina') ?? 0);

            // Sin costo en productos
            $manoObraHoy = 0.0;
            if (Schema::hasTable('nomina_diaria')) {
                $manoObraHoy = (float) DB::table('nomina_diaria')
                    ->where('restaurante_id', $restauranteActivo->id)
                    ->whereDate('fecha', $hoy)
                    ->sum('total_mano_obra');
            }

            $utilidadBrutaHoy = $ventasHoy; // sin costo de producto
            $utilidadNetaHoy  = $utilidadBrutaHoy - $manoObraHoy;

            // Clientes (sin total_compras / gasto_total en BD real)
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
    // NOTA: clientes solo tiene id, restaurante_id, nombre, email, telefono.
    // ─────────────────────────────────────────────────────────────────────────

    public function clientesFrecuentes(Request $request): JsonResponse
    {
        try {
            if (!class_exists('App\Models\Cliente')) {
                return response()->json(['success' => false, 'message' => 'Módulo de clientes no instalado.'], 404);
            }

            $restauranteActivo = app('restaurante_activo');
            $limite = (int) $request->get('limite', 10);

            // Sin total_compras/gasto_total → ordenamos por número de órdenes vinculadas
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
    // EXPORTAR
    // ─────────────────────────────────────────────────────────────────────────

    public function exportar(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'tipo'         => 'required|in:ventas,productos,clientes,utilidad,propinas,canales,paquete,retrasos',
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
            'data' => [
                'ventas_totales'  => round($ventas, 2),
                'gastos_totales'  => round($gastos, 2),
                'nominas_totales' => round($nominas, 2),
                'ganancia_neta'   => round($ganancia, 2),
                'periodo' => [
                    'inicio' => $fechaInicio,
                    'fin'    => $fechaFin
                ]
            ]
        ]);

    } catch (\Exception $e) {
        return $this->error('Error al generar reporte financiero', $e);
    }
}
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

        return response()->json([
            'success' => true,
            'data' => $productos
        ]);

    } catch (\Exception $e) {
        return $this->error('Error al generar reporte de productos', $e);
    }
}
}