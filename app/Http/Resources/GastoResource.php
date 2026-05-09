<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GastoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'concepto'       => $this->concepto,
            'categoria'      => $this->categoria,
            'categoria_formateada' => ucfirst($this->categoria),
            'monto'          => (float) $this->monto,
            'monto_formateado' => '$' . number_format((float)$this->monto, 2),
            'fecha'          => $this->fecha,
            'notas'          => $this->notas,
            'usuario'        => $this->usuario ? $this->usuario->name : 'N/A',
            'created_at'     => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
