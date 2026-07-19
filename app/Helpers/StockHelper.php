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
     * Obtiene la cantidad de un ingrediente en el pivot para un tamaño dado.
     * Mapea 'pequeno'/'mediano'/'grande' a columnas legacy, otros keys usan 'cantidad'.
     */
    private static function getCantidadPivot($pivot, string $tamano): float
    {
        $map = ['pequeno' => 'cantidad_pequeno', 'mediano' => 'cantidad_mediano', 'grande' => 'cantidad_grande'];
        $columna = $map[$tamano] ?? null;
        if ($columna && isset($pivot->$columna) && (float) $pivot->$columna > 0) {
            return (float) $pivot->$columna;
        }
        return (float) ($pivot->cantidad ?? 0);
    }

    /**
     * Actualiza el stock de un tamaño específico en el JSON tamanos_personalizados.
     */
    private static function actualizarStockTamano(Producto $producto, string $tamano, int $delta): void
    {
        $tams = $producto->tamanos_personalizados;
        if (!is_array($tams)) return;

        $modificado = false;
        foreach ($tams as &$t) {
            if (($t['key'] ?? '') === $tamano) {
                $stockActual = (int) ($t['stock'] ?? 0);
                $t['stock'] = max(0, $stockActual + $delta);
                $modificado = true;
                break;
            }
        }

        if ($modificado) {
            Producto::where('id', $producto->id)->update(['tamanos_personalizados' => json_encode($tams)]);
        }
    }

    /**
     * Descuenta el stock/ingredientes correspondientes a un detalle de orden.
     */
    public static function descontarStock(OrdenDetalle $detalle, $cantidad = null, $userId = null)
    {
        $cantidad = $cantidad ?? $detalle->cantidad;
        if ($cantidad <= 0) return;

        $detalle->loadMissing('producto.ingredientes');
        $producto = $detalle->producto;
        if (!$producto) return;

        $userId = $userId ?? auth()->id() ?? $detalle->orden->usuario_id ?? 1;

        if ($producto->ingredientes->isNotEmpty()) {
            $ingredientesAfectadosIds = [];
            $tamano = $detalle->tamano;

            foreach ($producto->ingredientes as $ingrediente) {
                $cantidadPivot = self::getCantidadPivot($ingrediente->pivot, $tamano);
                $cantidadADescontar = (float) $cantidadPivot * (float) $cantidad;

                if ($cantidadADescontar <= 0) continue;

                $stockAnterior = $ingrediente->stock_actual;
                $stockNuevo    = $stockAnterior - $cantidadADescontar;

                Ingrediente::where('id', $ingrediente->id)
                    ->decrement('stock_actual', $cantidadADescontar);

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

            if (!empty($ingredientesAfectadosIds)) {
                $productosARecalcular = Producto::whereHas('ingredientes', function($q) use ($ingredientesAfectadosIds) {
                    $q->whereIn('ingredientes.id', $ingredientesAfectadosIds);
                })->get();

                foreach ($productosARecalcular as $prod) {
                    $prod->recalcularStockDesdeIngredientes();
                }
            }
        } else {
            if ($producto->tiene_tamanos && $detalle->tamano) {
                self::actualizarStockTamano($producto, $detalle->tamano, -((int) $cantidad));
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

        $detalle->loadMissing('producto.ingredientes');
        $producto = $detalle->producto;
        if (!$producto) return;

        $userId = $userId ?? auth()->id() ?? $detalle->orden->usuario_id ?? 1;

        if ($producto->ingredientes->isNotEmpty()) {
            $ingredientesAfectadosIds = [];
            $tamano = $detalle->tamano;

            foreach ($producto->ingredientes as $ingrediente) {
                $cantidadPivot = self::getCantidadPivot($ingrediente->pivot, $tamano);
                $cantidadARestaurar = (float) $cantidadPivot * (float) $cantidad;

                if ($cantidadARestaurar <= 0) continue;

                $stockAnterior = $ingrediente->stock_actual;
                $stockNuevo    = $stockAnterior + $cantidadARestaurar;

                Ingrediente::where('id', $ingrediente->id)
                    ->increment('stock_actual', $cantidadARestaurar);

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

            if (!empty($ingredientesAfectadosIds)) {
                $productosARecalcular = Producto::whereHas('ingredientes', function($q) use ($ingredientesAfectadosIds) {
                    $q->whereIn('ingredientes.id', $ingredientesAfectadosIds);
                })->get();

                foreach ($productosARecalcular as $prod) {
                    $prod->recalcularStockDesdeIngredientes();
                }
            }
        } else {
            if ($producto->tiene_tamanos && $detalle->tamano) {
                self::actualizarStockTamano($producto, $detalle->tamano, (int) $cantidad);
            } else {
                Producto::where('id', $producto->id)
                    ->increment('stock', $cantidad);
            }
        }
    }
}
