<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gasto;
use App\Models\Orden;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Filtros
        if ($request->filled('fecha_desde')) {
            $query->whereDate('fecha', '>=', $request->fecha_desde);
        }

        if ($request->filled('fecha_hasta')) {
            $query->whereDate('fecha', '<=', $request->fecha_hasta);
        }

        if ($request->filled('categoria')) {
            $query->where('categoria', $request->categoria);
        }

        // Paginación
        $gastos = $query
            ->orderByDesc('fecha')
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 20));

        // Totales por categoría (SQL)
        $totalesCategoria = Gasto::where('restaurante_id', $restaurante->id)
            ->select('categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        // Total general
        $totalGeneral = Gasto::where('restaurante_id', $restaurante->id)
            ->sum('monto');

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
            'categoria'  => 'required|in:renta,nomina,servicios,insumos,marketing,mantenimiento,general',
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
            'categoria' => 'sometimes|in:renta,nomina,servicios,insumos,marketing,mantenimiento,general',
            'monto'     => 'sometimes|numeric|min:0.01',
            'fecha'     => 'sometimes|date',
            'notas'     => 'nullable|string|max:500',
        ]);

        $restaurante = app('restaurante_activo');

        $gasto = Gasto::where('restaurante_id', $restaurante->id)
            ->findOrFail($id);

        $gasto->update($request->only([
            'concepto',
            'categoria',
            'monto',
            'fecha',
            'notas'
        ]));

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

        $gasto = Gasto::where('restaurante_id', $restaurante->id)
            ->findOrFail($id);

        $gasto->delete();

        return response()->json([
            'success' => true,
            'message' => 'Gasto eliminado correctamente',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | RESUMEN FINANCIERO (ROI)
    |--------------------------------------------------------------------------
    */
    public function resumen(Request $request)
    {
        $restaurante = app('restaurante_activo');

        $inicio = $request->get('fecha_inicio', now()->startOfMonth()->toDateString());
        $fin    = $request->get('fecha_fin', now()->toDateString());

        // Total gastos
        $totalGastos = Gasto::where('restaurante_id', $restaurante->id)
            ->whereBetween('fecha', [$inicio, $fin])
            ->sum('monto');

        // Gastos por categoría
        $porCategoria = Gasto::where('restaurante_id', $restaurante->id)
            ->whereBetween('fecha', [$inicio, $fin])
            ->select('categoria', DB::raw('SUM(monto) as total'))
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        // Ventas
        $ventas = Orden::where('restaurante_id', $restaurante->id)
            ->whereBetween('created_at', [$inicio . ' 00:00:00', $fin . ' 23:59:59'])
            ->where('estado', 'CERRADA')
            ->sum('total');

        // Cálculos
        $utilidad = $ventas - $totalGastos;

        $roi = $totalGastos > 0
            ? round(($utilidad / $totalGastos) * 100, 2)
            : null;

        return response()->json([
            'success' => true,
            'data'    => [
                'periodo' => [
                    'inicio' => $inicio,
                    'fin'    => $fin,
                ],
                'ventas'         => (float) $ventas,
                'total_gastos'   => (float) $totalGastos,
                'utilidad_bruta' => (float) $utilidad,
                'roi_pct'        => $roi,
                'por_categoria'  => $porCategoria,
            ],
        ]);
    }
}