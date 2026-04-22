<?php


namespace App\Http\Controllers\Api;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IngredienteBajoStock implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int    $ingredienteId,
        public readonly string $nombre,
        public readonly float  $stockActual,
        public readonly float  $stockMinimo,
        public readonly int    $restauranteId,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("restaurante.{$this->restauranteId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ingrediente.bajo_stock';
    }

    public function broadcastWith(): array
    {
        return [
            'ingrediente_id' => $this->ingredienteId,
            'nombre'         => $this->nombre,
            'stock_actual'   => $this->stockActual,
            'stock_minimo'   => $this->stockMinimo,
            'sin_stock'      => $this->stockActual <= 0,
            'mensaje'        => $this->stockActual <= 0
                ? "🚨 {$this->nombre}: SIN STOCK"
                : "⚠️ {$this->nombre}: stock bajo ({$this->stockActual} restantes)",
        ];
    }
}