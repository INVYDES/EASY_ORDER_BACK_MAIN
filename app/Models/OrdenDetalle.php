<?php
// app/Models/OrdenDetalle.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrdenDetalle extends Model {
    use HasFactory;

    protected $table = 'orden_detalles';

    protected $fillable = [
        'orden_id',
        'producto_id',
        'paquete_id',
        'cantidad',
        'precio_unitario',  // ✅ NOMBRE CORRECTO
        'subtotal',
        'notas',
        'nom_comensal',
        'estado_preparacion',
        'reprocesado'
    ];

    protected $casts = [
        'precio_unitario' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'reprocesado' => 'boolean'
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
    return $this->belongsTo(Producto::class)->with('categoria'); // 👈 Agrega with
}
    public function paquete()
    {
        return $this->belongsTo(Paquete::class);
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