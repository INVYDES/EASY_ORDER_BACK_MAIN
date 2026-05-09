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
            'mesero'           => $this->usuario ? $this->usuario->name : 'N/A',
            'detalles'         => $this->whenLoaded('detalles', function() {
                return $this->detalles->map(fn($d) => [
                    'id'            => $d->id,
                    'producto_id'   => $d->producto_id,
                    'producto'      => $d->producto->nombre ?? 'N/A',
                    'categoria_id'  => $d->producto->categoria_id ?? null,
                    'categoria'     => $d->producto->categoria?->nombre ?? null,
                    'cantidad'      => $d->cantidad,
                    'subtotal'      => (float)$d->subtotal,
                    'estado'        => $d->estado_preparacion,
                ]);
            }),
            'created_at'       => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
