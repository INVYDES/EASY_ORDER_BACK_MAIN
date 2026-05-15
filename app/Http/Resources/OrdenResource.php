<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrdenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'folio'            => $this->folio,
            'total'            => (float) $this->total,
            'total_formateado' => '$' . number_format((float)$this->total, 2),
            'estado'           => $this->estado,
            'tipo_orden'       => $this->tipo_orden,
            'mesa'             => $this->mesa,
            'comensales'       => $this->whenLoaded('detalles', function() {
                return $this->detalles->pluck('nom_comensal')->unique()->filter()->values();
            }),
            'mesero'           => $this->usuario ? $this->usuario->name : 'N/A',
            'detalles'         => $this->whenLoaded('detalles', function() {
                return $this->detalles->map(fn($d) => [
                    'id'            => $d->id,
                    'producto_id'   => $d->producto_id,
                    'producto'      => $d->producto->nombre ?? 'N/A',
                    'categoria_id'  => $d->producto->categoria_id ?? null,
                    'categoria'     => $d->producto->categoria?->nombre ?? null,
                    'cantidad'      => $d->cantidad,
                    'mesa'          => $this->mesa,
                    'comensal'      => $d->nom_comensal,
                    'comensal_id'   => $d->comensal_id,
                    'subtotal'      => (float)$d->subtotal,
                    'estado'        => $d->estado_preparacion,
                    'cancelado'     => $d->trashed(),
                    'motivo_cancelacion' => $d->motivo_cancelacion,
                ]);
            }),
            'metodo_pago'      => $this->metodo_pago,
            'propina'          => (float) ($this->propina ?? 0),
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at'       => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
