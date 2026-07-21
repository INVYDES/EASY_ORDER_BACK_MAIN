<?php
// app/Models/Orden.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToTenant;

class Orden extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'ordenes';

    public static $tiposOrden = [
        'local'    => 'Local',
        'pickup'   => 'Para llevar',
        'delivery' => 'Domicilio'
    ];

    protected $fillable = [
        'restaurante_id',
        'cliente_id',
        'usuario_id',
        'mesa',
        'tipo_orden',
        'direccion_entrega',
        'telefono_contacto',
        'costo_envio',
        'tiempo_estimado_entrega',
        'metodo_pago',
        'total',
        'propina',
        'estado',
        'paypal_order_id',
        'lista_at'
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'propina' => 'decimal:2',
        'costo_envio' => 'decimal:2',
        'mesa' => 'integer',
        'tiempo_estimado_entrega' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'lista_at' => 'datetime'
    ];

    protected $attributes = [
        'estado' => 'ABIERTA',
        'tipo_orden' => 'local',
        'total' => 0,
        'propina' => 0,
        'costo_envio' => 0
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
    public function getTipoOrdenTextoAttribute(): string
    {
        return self::$tiposOrden[$this->tipo_orden] ?? $this->tipo_orden ?? 'Local';
    }

    public function getTipoOrdenBadgeAttribute(): array
    {
        return match ($this->tipo_orden) {
            'pickup'   => ['color' => 'orange', 'icono' => '🛍️', 'texto' => 'Recoger'],
            'delivery' => ['color' => 'purple', 'icono' => '🛵', 'texto' => 'Domicilio'],
            default    => ['color' => 'blue',   'icono' => '🏠', 'texto' => 'Local'],
        };
    }

    public function getFolioAttribute(): string
    {
        return 'ORD-' . str_pad($this->id, 6, '0', STR_PAD_LEFT);
    }

    public function getTotalFormateadoAttribute(): string
    {
        return '$' . number_format($this->total, 2);
    }

    public function getSubtotalAttribute()
    {
        return (float) ($this->total - ($this->propina ?? 0));
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
            'ABIERTA' => ['POR_PREPARAR', 'EN_PREPARACION', 'CANCELADA'],
            'POR_PREPARAR' => ['EN_PREPARACION', 'LISTA', 'ENTREGADA', 'CANCELADA'],
            'EN_PREPARACION' => ['LISTA', 'ENTREGADA', 'CANCELADA'],
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
        if ($detalles->isEmpty()) {
            if (!in_array($this->estado, ['CERRADA', 'CANCELADA', 'PAGADA'])) {
                $this->update(['estado' => 'CANCELADA']);
                
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
            return;
        }

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
            // Si el estado actual ya es ABIERTA y hay productos sin enviar (ABIERTA), se mantiene en ABIERTA.
            // De lo contrario, no se demota a ABIERTA y pasa a/se mantiene en POR_PREPARAR.
            $tieneAbiertos = $detalles->where('estado_preparacion', 'ABIERTA')->count() > 0;
            if ($this->estado === 'ABIERTA' && $tieneAbiertos) {
                $nuevoEstado = 'ABIERTA';
            } else {
                $nuevoEstado = 'POR_PREPARAR';
            }
        }

        if ($this->estado !== $nuevoEstado && !in_array($this->estado, ['CERRADA', 'CANCELADA', 'PAGADA'])) {
            $updateData = ['estado' => $nuevoEstado];
            if ($nuevoEstado === 'LISTA' && !$this->lista_at) {
                $updateData['lista_at'] = now();
            }
            $this->update($updateData);
            
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