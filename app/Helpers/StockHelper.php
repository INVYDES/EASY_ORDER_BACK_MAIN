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
                // Determinar qué cantidad usar dependiendo del tamaño (si el producto tiene tamaños)
                $cantidadPivot = $ingrediente->pivot->cantidad ?? 0;
                if ($producto->tiene_tamanos) {
                    if ($detalle->tamano === 'pequeno') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_pequeno ?? $cantidadPivot;
                    } elseif ($detalle->tamano === 'mediano') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_mediano ?? $cantidadPivot;
                    } elseif ($detalle->tamano === 'grande') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_grande ?? $cantidadPivot;
                    }
                }

                $cantidadADescontar = (float) $cantidadPivot * (float) $cantidad;

                // Saltar si no hay cantidad real que descontar (ej. tamaño sin receta)
                if ($cantidadADescontar <= 0) {
                    continue;
                }

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
            if ($producto->tiene_tamanos) {
                if ($detalle->tamano === 'pequeno') {
                    Producto::where('id', $producto->id)->decrement('stock_pequeno', $cantidad);
                } elseif ($detalle->tamano === 'mediano') {
                    Producto::where('id', $producto->id)->decrement('stock_mediano', $cantidad);
                } elseif ($detalle->tamano === 'grande') {
                    Producto::where('id', $producto->id)->decrement('stock_grande', $cantidad);
                }
            } else {
                Producto::where('id', $producto->id)
                    ->decrement('stock', $cantidad);
            }
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
                // Determinar qué cantidad usar dependiendo del tamaño (si el producto tiene tamaños)
                $cantidadPivot = $ingrediente->pivot->cantidad ?? 0;
                if ($producto->tiene_tamanos) {
                    if ($detalle->tamano === 'pequeno') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_pequeno ?? $cantidadPivot;
                    } elseif ($detalle->tamano === 'mediano') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_mediano ?? $cantidadPivot;
                    } elseif ($detalle->tamano === 'grande') {
                        $cantidadPivot = $ingrediente->pivot->cantidad_grande ?? $cantidadPivot;
                    }
                }

                $cantidadARestaurar = (float) $cantidadPivot * (float) $cantidad;

                // Saltar si no hay cantidad real que restaurar (ej. tamaño sin receta)
                if ($cantidadARestaurar <= 0) {
                    continue;
                }

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
            if ($producto->tiene_tamanos) {
                if ($detalle->tamano === 'pequeno') {
                    Producto::where('id', $producto->id)->increment('stock_pequeno', $cantidad);
                } elseif ($detalle->tamano === 'mediano') {
                    Producto::where('id', $producto->id)->increment('stock_mediano', $cantidad);
                } elseif ($detalle->tamano === 'grande') {
                    Producto::where('id', $producto->id)->increment('stock_grande', $cantidad);
                }
            } else {
                Producto::where('id', $producto->id)
                    ->increment('stock', $cantidad);
            }
        }
    }
}
