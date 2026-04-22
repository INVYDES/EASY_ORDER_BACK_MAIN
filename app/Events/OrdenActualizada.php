<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Orden;

class OrdenActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $orden;
    public $accion;
    public $restauranteId;

    public function __construct(Orden $orden, string $accion, int $restauranteId)
    {
        $this->orden = $orden;
        $this->accion = $accion;
        $this->restauranteId = $restauranteId;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel("restaurante.{$this->restauranteId}")
        ];
    }

    public function broadcastAs(): string
    {
        return 'orden.actualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'accion' => $this->accion,
            'orden' => [
                'id' => $this->orden->id,
                'folio' => 'ORD-' . str_pad($this->orden->id, 6, '0', STR_PAD_LEFT),
                'total' => $this->orden->total,
                'estado' => $this->orden->estado,
                'created_at' => $this->orden->created_at->toISOString(),
            ]
        ];
    }
}