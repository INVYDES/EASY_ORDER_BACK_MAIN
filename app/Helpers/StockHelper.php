<?php

namespace App\Helpers;

use App\Models\OrdenDetalle;
use App\Models\Ingrediente;
use App\Models\IngredienteMovimiento;
use App\Models\Producto;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockHelper
{
    /**
     * Descuenta el stock/ingredientes correspondientes a un detalle de orden.
     */
    public static function descontarStock(OrdenDetalle $detalle, $cantidad = null, $userId = null)
    {
        $cantidad = $cantidad ?? $detalle->cantidad;
        if ($cantidad <= 0) return;

        // Asegurarse de tener la relación cargada
        $detalle->loadMissing('producto.ingredientes');
        $producto = $detalle->producto;
        if (!$producto) return;

        $userId = $userId ?? auth()->id() ?? $detalle->orden->usuario_id ?? 1;

        if ($producto->ingredientes->isNotEmpty()) {
            $ingredientesAfectadosIds = [];
            foreach ($producto->ingredientes as $ingrediente) {
                $cantidadADescontar = (float) $ingrediente->pivot->cantidad * (float) $cantidad;
                $stockAnterior      = $ingrediente->stock_actual;
                $stockNuevo         = $stockAnterior - $cantidadADescontar;

                // Decrementar stock del ingrediente
                Ingrediente::where('id', $ingrediente->id)
                    ->decrement('stock_actual', $cantidadADescontar);

                // Registrar movimiento
                IngredienteMovimiento::create([
                    'ingrediente_id'      => $ingrediente->id,
                    'producto_id'         => $producto->id,
                    'orden_id'            => $detalle->orden_id,
                    'user_id'             => $userId,
                    'tipo'                => IngredienteMovimiento::TIPO_SALIDA,
                    'cantidad_anterior'   => $stockAnterior,
                    'cantidad_movimiento' => $cantidadADescontar,
                    'cantidad_nueva'      => $stockNuevo,
                    'motivo'              => "Pedido - Orden #{$detalle->orden_id}",
                ]);

                $ingredientesAfectadosIds[] = $ingrediente->id;
            }

            // Recalcular stock de TODOS los productos que compartan los ingredientes afectados
            if (!empty($ingredientesAfectadosIds)) {
                $productosARecalcular = Producto::whereHas('ingredientes', function($q) use ($ingredientesAfectadosIds) {
                    $q->whereIn('ingredientes.id', $ingredientesAfectadosIds);
                })->get();

                foreach ($productosARecalcular as $prod) {
                    $prod->recalcularStockDesdeIngredientes();
                }
            }
        } else {
            Producto::where('id', $producto->id)
                ->decrement('stock', $cantidad);
        }
    }

    /**
     * Restaura el stock/ingredientes correspondientes a un detalle de orden.
     */
    public static function restaurarStock(OrdenDetalle $detalle, $cantidad = null, $userId = null)
    {
        $cantidad = $cantidad ?? $detalle->cantidad;
        if ($cantidad <= 0) return;

        // Asegurarse de tener la relación cargada
        $detalle->loadMissing('producto.ingredientes');
        $producto = $detalle->producto;
        if (!$producto) return;

        $userId = $userId ?? auth()->id() ?? $detalle->orden->usuario_id ?? 1;

        if ($producto->ingredientes->isNotEmpty()) {
            $ingredientesAfectadosIds = [];
            foreach ($producto->ingredientes as $ingrediente) {
                $cantidadARestaurar = (float) $ingrediente->pivot->cantidad * (float) $cantidad;
                $stockAnterior      = $ingrediente->stock_actual;
                $stockNuevo         = $stockAnterior + $cantidadARestaurar;

                // Incrementar stock del ingrediente
                Ingrediente::where('id', $ingrediente->id)
                    ->increment('stock_actual', $cantidadARestaurar);

                // Registrar movimiento
                IngredienteMovimiento::create([
                    'ingrediente_id'      => $ingrediente->id,
                    'producto_id'         => $producto->id,
                    'orden_id'            => $detalle->orden_id,
                    'user_id'             => $userId,
                    'tipo'                => IngredienteMovimiento::TIPO_ENTRADA,
                    'cantidad_anterior'   => $stockAnterior,
                    'cantidad_movimiento' => $cantidadARestaurar,
                    'cantidad_nueva'      => $stockNuevo,
                    'motivo'              => "Cancelación/Devolución - Orden #{$detalle->orden_id}",
                ]);

                $ingredientesAfectadosIds[] = $ingrediente->id;
            }

            // Recalcular stock de TODOS los productos que compartan los ingredientes afectados
            if (!empty($ingredientesAfectadosIds)) {
                $productosARecalcular = Producto::whereHas('ingredientes', function($q) use ($ingredientesAfectadosIds) {
                    $q->whereIn('ingredientes.id', $ingredientesAfectadosIds);
                })->get();

                foreach ($productosARecalcular as $prod) {
                    $prod->recalcularStockDesdeIngredientes();
                }
            }
        } else {
            Producto::where('id', $producto->id)
                ->increment('stock', $cantidad);
        }
    }
}
