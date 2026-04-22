<?php
// app/Models/OrdenDetalle.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenDetalle extends Model
{
    protected $table = 'orden_detalles';

    protected $fillable = [
        'orden_id',
        'producto_id',
        'cantidad',
        'precio_unitario',  // ✅ NOMBRE CORRECTO
        'subtotal'
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2'
    ];

    /**
     * RELACIONES
     */
    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    /**
     * ACCESORS
     */
    public function getPrecioFormateadoAttribute(): string
    {
        return '$' . number_format($this->precio_unitario, 2);
    }

    public function getSubtotalFormateadoAttribute(): string
    {
        return '$' . number_format($this->subtotal, 2);
    }

    public function getProductoNombreAttribute(): string
    {
        return $this->producto->nombre ?? 'Producto eliminado';
    }

    /**
     * BOOT
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($detalle) {
            if (!$detalle->subtotal && $detalle->precio_unitario && $detalle->cantidad) {
                $detalle->subtotal = $detalle->precio_unitario * $detalle->cantidad;
            }
        });

        static::created(function ($detalle) {
            $detalle->orden->recalcularTotal();
        });

        static::updated(function ($detalle) {
            $detalle->orden->recalcularTotal();
        });

        static::deleted(function ($detalle) {
            $detalle->orden->recalcularTotal();
        });
    }
}