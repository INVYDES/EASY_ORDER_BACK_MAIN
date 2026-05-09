<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'propietario_id'     => $this->propietario_id,
            'name'               => $this->name,
            'username'           => $this->username,
            'email'              => $this->email,
            'telefono'           => $this->telefono,
            'restaurante_activo' => $this->restaurante_activo,
            'activo'             => (bool) $this->activo,
            'roles' => $this->whenLoaded('roles', function() {
    return $this->roles->map(fn($r) => [
        'id'     => $r->id,
        'nombre' => $r->nombre,
    ]);
}),
            'restaurante'        => $this->whenLoaded('restauranteActivo'),
            'created_at'         => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
