<?php
// app/Events/CajaActualizada.php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CajaActualizada implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $accion,        // 'abierta' | 'cerrada' | 'movimiento' | 'venta'
        public readonly int    $restauranteId,
        public readonly array  $datos = []
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurante.{$this->restauranteId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'caja.actualizada';
    }

    public function broadcastWith(): array
    {
        return [
            'accion' => $this->accion,
            'datos'  => $this->datos,
        ];
    }
}