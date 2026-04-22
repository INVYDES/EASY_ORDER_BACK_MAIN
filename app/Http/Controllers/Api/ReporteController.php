<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Orden;
use App\Models\Producto;
use App\Models\Cliente;
use Illuminate\Support\Facades\DB;

class ReporteController extends Controller
{
    /**
     * Reporte de ventas por período
     */
    public function ventasPorPeriodo(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after_or_equal:fecha_inicio',
                'grupo' => 'sometimes|in:dia,semana,mes'
            ]);

            $grupo = $request->get('grupo', 'dia');

            $query = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereBetween('created_at', [$request->fecha_inicio, $request->fecha_fin]);

            $ventas = match($grupo) {
                'dia' => $query->select(
                        DB::raw('DATE(created_at) as fecha'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas')
                    )
                    ->groupBy('fecha')
                    ->orderBy('fecha')
                    ->get(),
                'semana' => $query->select(
                        DB::raw('YEAR(created_at) as anio'),
                        DB::raw('WEEK(created_at) as semana'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas')
                    )
                    ->groupBy('anio', 'semana')
                    ->orderBy('anio')
                    ->orderBy('semana')
                    ->get(),
                'mes' => $query->select(
                        DB::raw('YEAR(created_at) as anio'),
                        DB::raw('MONTH(created_at) as mes'),
                        DB::raw('COUNT(*) as total_ordenes'),
                        DB::raw('SUM(total) as total_ventas')
                    )
                    ->groupBy('anio', 'mes')
                    ->orderBy('anio')
                    ->orderBy('mes')
                    ->get()
            };

            $totales = [
                'total_ordenes' => $query->count(),
                'total_ventas' => $query->sum('total'),
                'promedio_por_orden' => $query->avg('total')
            ];

            return response()->json([
                'success' => true,
                'data' => [
                    'periodo' => [
                        'inicio' => $request->fecha_inicio,
                        'fin' => $request->fecha_fin
                    ],
                    'ventas' => $ventas,
                    'totales' => $totales
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte de ventas',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Productos más vendidos
     */
    public function productosMasVendidos(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteActivo = app('restaurante_activo');

            $request->validate([
                'limite' => 'sometimes|integer|min:1|max:100',
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin' => 'sometimes|date|after_or_equal:fecha_inicio'
            ]);

            $limite = $request->get('limite', 10);
            
            $query = DB::table('orden_detalles')
                ->join('productos', 'orden_detalles.producto_id', '=', 'productos.id')
                ->join('ordenes', 'orden_detalles.orden_id', '=', 'ordenes.id')
                ->where('ordenes.restaurante_id', $restauranteActivo->id)
                ->where('ordenes.estado', 'CERRADA');

            if ($request->has('fecha_inicio')) {
                $query->where('ordenes.created_at', '>=', $request->fecha_inicio);
            }

            if ($request->has('fecha_fin')) {
                $query->where('ordenes.created_at', '<=', $request->fecha_fin);
            }

            $productos = $query->select(
                    'productos.id',
                    'productos.nombre',
                    DB::raw('SUM(orden_detalles.cantidad) as total_vendido'),
                    DB::raw('SUM(orden_detalles.subtotal) as total_ventas'),
                    DB::raw('COUNT(DISTINCT ordenes.id) as veces_vendido')
                )
                ->groupBy('productos.id', 'productos.nombre')
                ->orderByDesc('total_vendido')
                ->limit($limite)
                ->get();

            return response()->json([
                'success' => true,
                'data' => $productos
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al generar reporte de productos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Dashboard con estadísticas generales
     */
    public function dashboard(Request $request)
    {
        try {
            $user = $request->user();
            $restauranteActivo = app('restaurante_activo');

            // Ventas hoy
            $ventasHoy = Orden::where('restaurante_id', $restauranteActivo->id)
                ->where('estado', 'CERRADA')
                ->whereDate('created_at', today())
                ->sum('total');

            // Órdenes hoy
            $ordenesHoy = Orden::where('restaurante_id', $restauranteActivo->id)
                ->whereDate('created_at', today())
                ->count();

            // Órdenes por estado
            $ordenesPorEstado = Orden::where('restaurante_id', $restauranteActivo->id)
                ->select('estado', DB::raw('COUNT(*) as total'))
                ->groupBy('estado')
                ->get();

            // Top clientes
            $topClientes = [];
            if (class_exists('App\Models\Cliente')) {
                $topClientes = Cliente::where('restaurante_id', $restauranteActivo->id)
                    ->orderByDesc('gasto_total')
                    ->limit(5)
                    ->get(['id', 'nombre', 'apellido', 'total_compras', 'gasto_total']);
            }

            // Productos bajos en stock (opcional)
            $productosBajoStock = Producto::where('restaurante_id', $restauranteActivo->id)
                ->where('activo', true)
                ->orderBy('stock')
                ->limit(10)
                ->get(['id', 'nombre', 'stock']);

            return response()->json([
                'success' => true,
                'data' => [
                    'ventas_hoy' => $ventasHoy,
                    'ordenes_hoy' => $ordenesHoy,
                    'ordenes_por_estado' => $ordenesPorEstado,
                    'top_clientes' => $topClientes,
                    'productos_bajo_stock' => $productosBajoStock,
                    'fecha' => today()->format('Y-m-d')
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reporte de clientes frecuentes
     */
    public function clientesFrecuentes(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!class_exists('App\Models\Cliente')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Módulo de clientes no instalado'
                ], 404);
            }

            $restauranteActivo = app('restaurante_activo');

            $limite = $request->get('limite', 10);

            $clientes = Cliente::where('restaurante_id', $restauranteActivo->id)
                ->where('total_compras', '>', 0)
                ->orderByDesc('total_compras')
                ->orderByDesc('gasto_total')
                ->limit($limite)
                ->get(['id', 'nombre', 'apellido', 'email', 'telefono', 'total_compras', 'gasto_total']);

            return response()->json([
                'success' => true,
                'data' => $clientes
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener clientes frecuentes',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar reporte (simulado)
     */
    public function exportar(Request $request)
    {
        try {
            $user = $request->user();
            
            $request->validate([
                'tipo' => 'required|in:ventas,productos,clientes',
                'formato' => 'required|in:pdf,excel,csv',
                'fecha_inicio' => 'sometimes|date',
                'fecha_fin' => 'sometimes|date'
            ]);

            // Aquí iría la lógica para generar PDF/Excel
            // Por ahora simulamos la respuesta

            return response()->json([
                'success' => true,
                'message' => "Reporte de {$request->tipo} en formato {$request->formato} generado correctamente",
                'data' => [
                    'url' => url("/reportes/download/{$request->tipo}.{$request->formato}"),
                    'tipo' => $request->tipo,
                    'formato' => $request->formato
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al exportar reporte',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
