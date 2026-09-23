<?php

namespace App\Traits;

use App\Models\Orden;
use App\Models\OrdenDetalle;

/**
 * Aviso de cambio de precio cuando el producto o paquete está capturado en
 * órdenes que todavía no se han cobrado.
 *
 * Se consideran "sin cobrar" todos los estados excepto PAGADA y CANCELADA.
 * El precio ya capturado en esas órdenes NO se modifica: el nuevo precio solo
 * aplica a órdenes nuevas. Estas utilidades sirven para avisarlo antes de
 * aplicar el cambio (`forzar_precio`) y para simular cuánto cambiaría el total
 * de cada cuenta afectada.
 */
trait DetectaCambioDePrecioEnOrdenes
{
    /** Estados en los que una orden ya no admite cambios de precio. */
    protected function estadosSinCobrar(): array
    {
        return ['PAGADA', 'CANCELADA'];
    }

    /**
     * Cambio del precio base. Devuelve [] cuando el precio no cambia.
     */
    protected function cambioDePrecioBase($precioActual, $precioNuevo, string $etiqueta = 'Precio'): array
    {
        if ($precioNuevo === null || $precioNuevo === '') {
            return [];
        }

        $antes   = round((float) $precioActual, 2);
        $despues = round((float) $precioNuevo, 2);

        if ($antes === $despues) {
            return [];
        }

        return [[
            'campo'   => 'precio',
            'nombre'  => $etiqueta,
            'antes'   => $antes,
            'despues' => $despues,
        ]];
    }

    /**
     * Normaliza el nombre de un tamaño para poder casarlo entre el producto y
     * las líneas de las órdenes (que guardan el nombre tal como se capturó).
     */
    protected function normalizarTamano($nombre): string
    {
        return mb_strtolower(trim((string) $nombre));
    }

    /**
     * Mapa de precios a aplicar sobre las líneas de las órdenes, a partir de los
     * cambios detectados: precio base y/o precios de tamaños.
     * Devuelve ['base' => float|null, 'tamanos' => ['grande' => 55.0, ...]].
     *
     * Los tamaños que no cambian se conservan con su precio actual para poder
     * distinguir una línea regida por un tamaño de una que no lo está.
     */
    protected function mapaPreciosNuevos(array $tamanosActuales, array $cambios): array
    {
        $mapa = ['base' => null, 'tamanos' => []];

        foreach (array_values($tamanosActuales) as $tam) {
            if (!empty($tam['nombre'])) {
                $mapa['tamanos'][$this->normalizarTamano($tam['nombre'])] = round((float) ($tam['precio'] ?? 0), 2);
            }
        }

        foreach ($cambios as $cambio) {
            if (($cambio['campo'] ?? '') === 'precio') {
                $mapa['base'] = round((float) $cambio['despues'], 2);
                continue;
            }

            if (!empty($cambio['tamano'])) {
                $mapa['tamanos'][$this->normalizarTamano($cambio['tamano'])] = round((float) $cambio['despues'], 2);
            }
        }

        return $mapa;
    }

    /**
     * Precio nuevo que le correspondería a una línea de la orden.
     * Devuelve null cuando el cambio de precio no afecta a esa línea.
     */
    protected function precioNuevoDeLinea(OrdenDetalle $detalle, array $preciosNuevos): ?float
    {
        $tamano = $this->normalizarTamano($detalle->tamano ?? '');

        if ($tamano !== '' && array_key_exists($tamano, $preciosNuevos['tamanos'] ?? [])) {
            return $preciosNuevos['tamanos'][$tamano];
        }

        // Línea sin tamaño (o con un tamaño que ya no existe): aplica el precio base.
        return $preciosNuevos['base'] ?? null;
    }

    /**
     * Resumen del impacto del cambio: cuántas cuentas se verían afectadas y
     * cuánto cambiaría el total sumando todas ellas.
     */
    protected function impactoDeOrdenes(array $ordenes): array
    {
        $diferencia = 0.0;

        foreach ($ordenes as $orden) {
            $diferencia += (float) ($orden['diferencia'] ?? 0);
        }

        return [
            'ordenes'    => count($ordenes),
            'diferencia' => round($diferencia, 2),
        ];
    }

    /**
     * Órdenes sin cobrar que incluyen la línea indicada (un producto o un paquete),
     * con el detalle completo de cada cuenta y la simulación del impacto.
     *
     * @param string $columna 'producto_id' o 'paquete_id'
     */
    protected function ordenesSinCobrarConLinea(int $restauranteId, string $columna, int $lineaId, array $preciosNuevos = []): array
    {
        $ordenes = Orden::where('restaurante_id', $restauranteId)
            ->whereNotIn('estado', $this->estadosSinCobrar())
            ->whereHas('detalles', fn ($q) => $q->where($columna, $lineaId))
            ->with([
                // Se cargan TODAS las líneas de la orden (no solo la del producto o
                // paquete) para que el aviso pueda mostrar la cuenta completa.
                'detalles' => fn ($q) => $q->with(['producto', 'paquete'])->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();

        return $ordenes->map(function ($orden) use ($columna, $lineaId, $preciosNuevos) {
            // Solo las líneas cuyo precio se está cambiando.
            $detallesObjetivo = $orden->detalles->where($columna, $lineaId);

            // Simulación del impacto: cuánto cambiaría el subtotal de cada línea
            // afectada y, en conjunto, el total de la cuenta.
            $nuevosPorLinea  = [];
            $diferenciaTotal = 0.0;

            foreach ($detallesObjetivo as $d) {
                // Las líneas sin precio propio (0) no se revalorizan: un paquete se
                // guarda como una línea por componente y solo la primera lleva el
                // precio, así que el resto debe seguir en 0.
                if (round((float) $d->precio_unitario, 2) <= 0) {
                    continue;
                }

                $precioNuevo = $this->precioNuevoDeLinea($d, $preciosNuevos);

                // El cambio no afecta a esta línea, o su precio no varía.
                if ($precioNuevo === null || round($precioNuevo, 2) === round((float) $d->precio_unitario, 2)) {
                    continue;
                }

                $subtotalNuevo = round((float) $d->cantidad * $precioNuevo, 2);
                $diferencia    = round($subtotalNuevo - (float) $d->subtotal, 2);

                $nuevosPorLinea[$d->id] = [
                    'subtotal_nuevo' => $subtotalNuevo,
                    'diferencia'     => $diferencia,
                ];

                // Las líneas canceladas ya no forman parte del total de la cuenta.
                if (empty($d->motivo_cancelacion)) {
                    $diferenciaTotal += $diferencia;
                }
            }

            return [
                'id'     => $orden->id,
                'folio'  => $orden->folio ?? ('ORD-' . str_pad($orden->id, 6, '0', STR_PAD_LEFT)),
                'estado' => $orden->estado,
                'mesa'   => $orden->mesa,
                'creada' => $orden->created_at ? $orden->created_at->format('d/m/Y H:i') : null,
                'total'  => (float) $orden->total,
                // Total que tendría la cuenta con el precio nuevo aplicado.
                'total_nuevo' => round((float) $orden->total + $diferenciaTotal, 2),
                'diferencia'  => round($diferenciaTotal, 2),
                'cantidad' => (float) $detallesObjetivo->sum('cantidad'),
                'precios_unitarios' => $detallesObjetivo->pluck('precio_unitario')
                    ->map(fn ($p) => (float) $p)
                    ->unique()
                    ->values()
                    ->all(),
                // Detalle completo de la orden para consultarlo desde el aviso.
                'detalles' => $orden->detalles->map(function ($d) use ($columna, $lineaId, $nuevosPorLinea) {
                    $nombre = $d->producto->nombre ?? ($d->paquete->nombre ?? 'Producto eliminado');
                    if (!empty($d->tamano)) {
                        $nombre .= ' (' . $d->tamano . ')';
                    }

                    $lineaNueva = $nuevosPorLinea[$d->id] ?? null;

                    return [
                        'id'              => $d->id,
                        'producto_id'     => $d->producto_id,
                        'paquete_id'      => $d->paquete_id,
                        'nombre'          => $nombre,
                        'cantidad'        => (float) $d->cantidad,
                        'precio_unitario' => (float) $d->precio_unitario,
                        'subtotal'        => (float) $d->subtotal,
                        // Marca la línea (producto o paquete) cuyo precio se va a modificar.
                        'es_afectado'     => (int) $d->{$columna} === (int) $lineaId,
                        'cancelado'       => !empty($d->motivo_cancelacion),
                        // Solo se llenan cuando el cambio afecta a esta línea.
                        'subtotal_nuevo'  => $lineaNueva['subtotal_nuevo'] ?? null,
                        'diferencia'      => $lineaNueva['diferencia'] ?? null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();
    }
}
