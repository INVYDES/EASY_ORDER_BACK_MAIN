<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class GastoController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | LISTADO DE GASTOS (PAGINADO + FILTROS)
    |--------------------------------------------------------------------------
    */
    public function index(Request $request)
    {
        $restaurante = app('restaurante_activo');

        $query = Gasto::where('restaurante_id', $restaurante->id);

        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->fecha_desde);
        }
        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->fecha_hasta);
        }
        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        $gastos = $query
            ->orderByDesc('fecha')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        $totalesCategoria = Gasto::where('restaurante_id', $restaurante->id)
            ->select('categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        $totalGeneral = Gasto::where('restaurante_id', $restaurante->id)->sum('monto');

        return response()->json([
            'success' => true,
            'data'    => $gastos,
            'totales' => [
                'total'         => round($totalGeneral, 2),
                'por_categoria' => $totalesCategoria,
            ],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CREAR GASTO
    |--------------------------------------------------------------------------
    */
    public function store(Request $request)
    {
        $request->validate([
            'concepto'   => 'required|string|max:200',
            'categoria'  => 'required|in:renta,nomina,servicios,insumos,empaque,comisiones,marketing,mantenimiento,software,general',
            'monto'      => 'required|numeric|min:0.01',
            'fecha'      => 'required|date',
            'notas'      => 'nullable|string|max:500',
        ]);

        $restaurante = app('restaurante_activo');

        $gasto = Gasto::create([
            'restaurante_id' => $restaurante->id,
            'user_id'        => $request->user()->id,
            'concepto'       => $request->concepto,
            'categoria'      => $request->categoria,
            'monto'          => $request->monto,
            'fecha'          => $request->fecha,
            'notas'          => $request->notas,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Gasto registrado correctamente',
            'data'    => $gasto,
        ], 201);
    }

    /*
    |--------------------------------------------------------------------------
    | ACTUALIZAR GASTO
    |--------------------------------------------------------------------------
    */
    public function update(Request $request, $id)
    {
        $request->validate([
            'concepto'  => 'sometimes|string|max:200',
            'categoria' => 'sometimes|in:renta,nomina,servicios,insumos,empaque,comisiones,marketing,mantenimiento,software,general',
            'monto'     => 'sometimes|numeric|min:0.01',
            'fecha'     => 'sometimes|date',
            'notas'     => 'nullable|string|max:500',
        ]);

        $restaurante = app('restaurante_activo');

        $gasto = Gasto::where('restaurante_id', $restaurante->id)->findOrFail($id);
        $gasto->update($request->only(['concepto', 'categoria', 'monto', 'fecha', 'notas']));

        return response()->json([
            'success' => true,
            'message' => 'Gasto actualizado correctamente',
            'data'    => $gasto,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ELIMINAR GASTO
    |--------------------------------------------------------------------------
    */
    public function destroy($id)
    {
        $restaurante = app('restaurante_activo');

        $gasto = Gasto::where('restaurante_id', $restaurante->id)->findOrFail($id);
        $gasto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gasto eliminado correctamente',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RESUMEN FINANCIERO COMPLETO
    |
    | Incluye:
    |   - KPIs avanzados (Margen contribución, punto equilibrio, etc.)
    |   - Utilidad objetivo vs real (dos campos separados)
    |   - Inversión inicial (configurable por restaurante)
    |   - Venta mes: acumulado del 1 al último día del mes consultado
    |   - Gastos variables: insumos + empaque + comisiones (acumulado mensual)
    |   - Gastos operativos: renta + nomina + servicios + software + marketing
    |   - Ganancia neta mensual: venta_mes - gastos_variables - gastos_operativos
    |   - ROI sobre inversión inicial y sobre gastos totales del período
    |--------------------------------------------------------------------------
    */
    public function resumen(Request $request)
    {
        $restaurante = app('restaurante_activo');

        $request->validate([
            'fecha_inicio'      => 'sometimes|date',
            'fecha_fin'         => 'sometimes|date|after_or_equal:fecha_inicio',
            'utilidad_objetivo' => 'sometimes|numeric|min:0',
        ]);

        // ── Período ──────────────────────────────────────────────────────────
        $inicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fin    = $request->get('fecha_fin',    now()->toDateString());

        // ── Mes completo para acumulados mensuales ───────────────────────────
        $mesInicio = Carbon::parse($inicio)->startOfMonth()->toDateString();
        $mesFin    = Carbon::parse($inicio)->endOfMonth()->toDateString();

        // ── Ventas del período consultado ─────────────────────────────────────
        $ventas = Orden::where('restaurante_id', $restaurante->id)
            ->whereBetween('created_at', [$inicio . ' 00:00:00', $fin . ' 23:59:59'])
            ->where('estado', 'CERRADA')
            ->sum('total');

        // ── Venta mes: acumulado del día 1 al último del mes ─────────────────
        $ventaMes = Orden::where('restaurante_id', $restaurante->id)
            ->whereBetween('created_at', [$mesInicio . ' 00:00:00', $mesFin . ' 23:59:59'])
            ->where('estado', 'CERRADA')
            ->sum('total');

        // ── Gastos del período ────────────────────────────────────────────────
        $gastosPeriodo = Gasto::where('restaurante_id', $restaurante->id)
            ->whereBetween('fecha', [$inicio, $fin]);

        $totalGastos  = (clone $gastosPeriodo)->sum('monto');
        $porCategoria = (clone $gastosPeriodo)
            ->select('categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        // ── Gastos variables del mes (insumos + empaque + comisiones) ─────────
        $categoriasVariables  = ['insumos', 'empaque', 'comisiones'];
        $gastosVariablesMes = Gasto::where('restaurante_id', $restaurante->id)
            ->whereBetween('fecha', [$mesInicio, $mesFin])
            ->whereIn('categoria', $categoriasVariables)
            ->sum('monto');

        // ── Gastos operativos del mes (renta + nomina + servicios + software + marketing)
        $categoriasOperativas = ['renta', 'nomina', 'servicios', 'software', 'marketing'];
        $gastosOperativosMes  = Gasto::where('restaurante_id', $restaurante->id)
            ->whereBetween('fecha', [$mesInicio, $mesFin])
            ->whereIn('categoria', $categoriasOperativas)
            ->sum('monto');

        // ── Ganancia neta mensual ─────────────────────────────────────────────
        $gananciaNeta = $ventaMes - $gastosVariablesMes - $gastosOperativosMes;

        // ═══════════════════════════════════════════════════════════════════════
        // ── KPIs AVANZADOS ─────────────────────────────────────────────────────
        // ═══════════════════════════════════════════════════════════════════════

        // Margen de contribución
        $margenContribucion = $ventaMes > 0
            ? round(1 - ($gastosVariablesMes / $ventaMes), 4)
            : 0;

        // Punto de equilibrio (ventas necesarias para cubrir costos fijos)
        $puntoEquilibrio = $margenContribucion > 0
            ? round($gastosOperativosMes / $margenContribucion, 2)
            : null;

        // Ventas actuales vs punto de equilibrio (%)
        $ventasVsEquilibrioPct = $puntoEquilibrio > 0 && $puntoEquilibrio !== null
            ? round(($ventaMes / $puntoEquilibrio) * 100, 2)
            : null;

        // % de utilidad sobre ventas
        $utilidadPct = $ventaMes > 0
            ? round(($gananciaNeta / $ventaMes) * 100, 2)
            : 0;

        // ── Utilidad objetivo vs real ─────────────────────────────────────────
        $utilidadObjetivo = $request->filled('utilidad_objetivo')
            ? (float) $request->utilidad_objetivo
            : (float) ($restaurante->utilidad_objetivo_mensual ?? 0);

        $utilidadReal       = $ventas - $totalGastos;
        $brechaVsObjetivo   = $utilidadReal - $utilidadObjetivo;
        $cumplimientoPct    = $utilidadObjetivo > 0
            ? round(($utilidadReal / $utilidadObjetivo) * 100, 2)
            : null;

        // ── ROI general del período ───────────────────────────────────────────
        $roi = $totalGastos > 0
            ? round(($utilidadReal / $totalGastos) * 100, 2)
            : null;

        // ── Semáforo para ROI (visual) ────────────────────────────────────────
        $roiSemaforo = match(true) {
            $roi === null               => 'gris',
            $roi < 5                    => 'rojo',
            $roi >= 5 && $roi <= 15     => 'amarillo',
            $roi > 15                   => 'verde',
            default                     => 'gris',
        };

        // ── Inversión inicial del restaurante ─────────────────────────────────
        $inversionInicial = (float) ($restaurante->inversion_inicial ?? 0);

        // ROI sobre inversión inicial
        $ventasHistoricas = Orden::where('restaurante_id', $restaurante->id)
            ->where('estado', 'CERRADA')->sum('total');
        $gastosHistoricos = Gasto::where('restaurante_id', $restaurante->id)->sum('monto');
        $utilidadHistorica = $ventasHistoricas - $gastosHistoricos;

        $roiInversionInicial = $inversionInicial > 0
            ? round(($utilidadHistorica / $inversionInicial) * 100, 2)
            : null;

        $paybackMeses = ($inversionInicial > 0 && $gananciaNeta > 0)
            ? round($inversionInicial / $gananciaNeta, 1)
            : null;

        // ── ROI por producto (placeholder - requiere implementación) ──────────
        $roiProducto = null;

        return response()->json([
            'success' => true,
            'data'    => [
                // ── Período consultado ────────────────────────────────────────
                'periodo' => [
                    'inicio' => $inicio,
                    'fin'    => $fin,
                    'mes'    => [
                        'inicio' => $mesInicio,
                        'fin'    => $mesFin,
                    ],
                ],

                // ── Inversión inicial ─────────────────────────────────────────
                'inversion_inicial' => round($inversionInicial, 2),

                // ── KPIs avanzados ────────────────────────────────────────────
                'kpis_avanzados' => [
                    'margen_contribucion_pct'       => round($margenContribucion * 100, 2),
                    'punto_equilibrio_ventas'       => $puntoEquilibrio,
                    'ventas_vs_equilibrio_pct'      => $ventasVsEquilibrioPct,
                    'utilidad_pct'                  => $utilidadPct,
                    'roi_semaforo'                  => $roiSemaforo,
                    'roi_producto'                  => $roiProducto,
                ],

                // ── Utilidad: objetivo vs real ────────────────────────────────
                'utilidad_objetivo'     => round($utilidadObjetivo, 2),
                'utilidad_real'         => round($utilidadReal, 2),
                'brecha_vs_objetivo'    => round($brechaVsObjetivo, 2),
                'cumplimiento_pct'      => $cumplimientoPct,

                // ── Ventas ────────────────────────────────────────────────────
                'ventas_periodo'        => round($ventas, 2),
                'venta_mes'             => round($ventaMes, 2),

                // ── Gastos variables del mes ──────────────────────────────────
                'gastos_variables'      => round($gastosVariablesMes, 2),
                'gastos_variables_detalle' => [
                    'insumos'    => round((float) ($porCategoria['insumos']    ?? 0), 2),
                    'empaque'    => round((float) ($porCategoria['empaque']    ?? 0), 2),
                    'comisiones' => round((float) ($porCategoria['comisiones'] ?? 0), 2),
                ],

                // ── Gastos operativos del mes ─────────────────────────────────
                'gastos_operativos'     => round($gastosOperativosMes, 2),
                'gastos_operativos_detalle' => [
                    'renta'      => round((float) ($porCategoria['renta']      ?? 0), 2),
                    'nomina'     => round((float) ($porCategoria['nomina']     ?? 0), 2),
                    'servicios'  => round((float) ($porCategoria['servicios']  ?? 0), 2),
                    'software'   => round((float) ($porCategoria['software']   ?? 0), 2),
                    'marketing'  => round((float) ($porCategoria['marketing']  ?? 0), 2),
                ],

                // ── Ganancia neta mensual ─────────────────────────────────────
                'ganancia_neta_mensual' => round($gananciaNeta, 2),
                'margen_neto_pct'       => $ventaMes > 0
                    ? round(($gananciaNeta / $ventaMes) * 100, 2)
                    : 0,

                // ── Total gastos y desglose ───────────────────────────────────
                'total_gastos'          => round($totalGastos, 2),
                'por_categoria'         => $porCategoria,

                // ── ROI ───────────────────────────────────────────────────────
                'roi_periodo_pct'           => $roi,
                'roi_inversion_inicial_pct' => $roiInversionInicial,
                'payback_meses_estimado'    => $paybackMeses,

                // ── Histórico ─────────────────────────────────────────────────
                'historico' => [
                    'ventas_totales'     => round($ventasHistoricas, 2),
                    'gastos_totales'     => round($gastosHistoricos, 2),
                    'utilidad_acumulada' => round($utilidadHistorica, 2),
                ],
            ],
        ]);
    }
}