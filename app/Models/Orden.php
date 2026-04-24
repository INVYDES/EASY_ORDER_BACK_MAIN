<?php
// app/Models/Orden.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Orden extends Model
{
    use SoftDeletes;

    protected $table = 'ordenes';

    protected $fillable = [
        'restaurante_id',
        'cliente_id',
        'usuario_id',      // 👈 ASÍ SE LLAMA EN TU BD, NO user_id
        'mesa',            // 👈 NUEVO
        'metodo_pago',     // 👈 NUEVO
        'total',
        'propina',         // 👈 NUEVO
        'estado'
        // Nota: No incluyas 'fecha' porque usas created_at/updated_at
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'propina' => 'decimal:2',
        'mesa' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'estado' => 'ABIERTA',
        'total' => 0,
        'propina' => 0
    ];

    /**
     * RELACIONES
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class);
    }

    // 👈 RELACIÓN CON USUARIO (nombre correcto en tu BD)
    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    // Mantener por compatibilidad si usas 'user' en el código
    public function user()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    public function detalles()
    {
        return $this->hasMany(OrdenDetalle::class, 'orden_id');
    }

    /**
     * SCOPES
     */
    public function scopeDelRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    public function scopePorEstado($query, $estado)
    {
        return $query->where('estado', $estado);
    }

    public function scopePorMesa($query, $mesa)
    {
        return $query->where('mesa', $mesa);
    }

    public function scopePorMetodoPago($query, $metodo)
    {
        return $query->where('metodo_pago', $metodo);
    }

    public function scopeDeHoy($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeDeFecha($query, $fecha)
    {
        return $query->whereDate('created_at', $fecha);
    }

    public function scopeEntreFechas($query, $desde, $hasta)
    {
        return $query->whereBetween('created_at', [$desde, $hasta]);
    }

    /**
     * ACCESORS
     */
    public function getFolioAttribute(): string
    {
        return 'ORD-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    public function getTotalFormateadoAttribute(): string
    {
        return '$' . number_format($this->total, 2);
    }

    public function getPropinaFormateadaAttribute(): string
    {
        return '$' . number_format($this->propina, 2);
    }

    public function getEstadoTextoAttribute(): string
    {
        $textos = [
            'ABIERTA' => 'Abierta',
            'POR_PREPARAR' => 'Por preparar',
            'EN_PREPARACION' => 'En preparación',
            'LISTA' => 'Lista para servir',
            'ENTREGADA' => 'Entregada',
            'CERRADA' => 'Cerrada',
            'PAGADA' => 'Pagada',
            'CANCELADA' => 'Cancelada'
        ];
        
        return $textos[$this->estado] ?? $this->estado;
    }

    public function getEstadoColorAttribute(): string
    {
        $colores = [
            'ABIERTA' => 'yellow',
            'POR_PREPARAR' => 'orange',
            'EN_PREPARACION' => 'blue',
            'LISTA' => 'green',
            'ENTREGADA' => 'purple',
            'CERRADA' => 'gray',
            'PAGADA' => 'emerald',
            'CANCELADA' => 'red'
        ];
        
        return $colores[$this->estado] ?? 'gray';
    }

    public function getCantidadProductosAttribute(): int
    {
        return $this->detalles->sum('cantidad');
    }

    public function getProductosUnicosAttribute(): int
    {
        return $this->detalles->count();
    }

    public function getCreatedAtFormateadoAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    public function getCreatedAtHumanoAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * MÉTODOS PERSONALIZADOS
     */
    public function puedeCambiarEstado(string $nuevoEstado): bool
    {
        $transiciones = [
            'ABIERTA' => ['POR_PREPARAR', 'CANCELADA'],
            'POR_PREPARAR' => ['EN_PREPARACION', 'CANCELADA'],
            'EN_PREPARACION' => ['LISTA', 'CANCELADA'],
            'LISTA' => ['ENTREGADA', 'CERRADA'],
            'ENTREGADA' => ['CERRADA', 'PAGADA'],
            'CERRADA' => ['PAGADA'],
            'PAGADA' => [],
            'CANCELADA' => []
        ];

        return in_array($nuevoEstado, $transiciones[$this->estado] ?? []);
    }

    public function esEditable(): bool
    {
        return in_array($this->estado, ['ABIERTA', 'POR_PREPARAR', 'EN_PREPARACION', 'LISTA']);
    }

    public function recalcularTotal(): float
    {
        $subtotal = $this->detalles()->sum('subtotal');
        $total = $subtotal + ($this->propina ?? 0);
        $this->update(['total' => $total]);
        return $total;
    }

    /**
     * Verifica los estados de los detalles y actualiza el estado de la orden global.
     * Ahora permite que la orden se vea como "LISTA" para el mesero si hay productos terminados,
     * aunque otros sigan en preparación.
     */
    public function verificarYActualizarEstadoGlobal()
    {
        $detalles = $this->detalles()->with('producto.categoria')->get();
        if ($detalles->isEmpty()) return;

        $total = $detalles->count();
        $listos = $detalles->where('estado_preparacion', 'LISTO')->count();
        $enPreparacion = $detalles->where('estado_preparacion', 'EN_PREPARACION')->count();
        $entregados = $detalles->where('estado_preparacion', 'ENTREGADO')->count();
        
        $nuevoEstado = $this->estado;

        // Si todo está entregado, la orden está ENTREGADA
        if ($entregados === $total) {
            $nuevoEstado = 'ENTREGADA';
        } 
        // Si hay cosas listas (y no todo entregado), marcar como LISTA para que el mesero la vea
        elseif ($listos > 0) {
            $nuevoEstado = 'LISTA';
        }
        // Si hay cosas en preparación
        elseif ($enPreparacion > 0) {
            $nuevoEstado = 'EN_PREPARACION';
        } 
        // Por defecto
        else {
            $nuevoEstado = 'POR_PREPARAR';
        }

        if ($this->estado !== $nuevoEstado && !in_array($this->estado, ['ENTREGADA', 'CERRADA', 'CANCELADA', 'PAGADA'])) {
            $this->update(['estado' => $nuevoEstado]);
            
            // Emitir evento si es necesario (el controlador lo debería hacer, pero aseguramos estado correcto)
            try {
                broadcast(new \App\Events\OrdenActualizada(
                    $this->load(['usuario:id,name,username', 'detalles.producto.categoria']), 
                    'estado_cambiado', 
                    $this->restaurante_id
                ));
            } catch (\Exception $e) {
                // ignorar
            }
        }
    }
}