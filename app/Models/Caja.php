<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Caja extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cajas';

    protected $fillable = [
        'restaurante_id',
        'usuario_apertura_id',
        'usuario_cierre_id',
        'fecha_apertura',
        'fecha_cierre',
        'monto_inicial',
        'monto_final',
        'ventas_efectivo',
        'ventas_tarjeta',
        'ventas_transferencia',
        'ventas_paypal',       // ← nuevo
        'ventas_mercadopago',  // ← nuevo
        'total_ordenes',
        'diferencia',
        'observaciones_cierre',
        'estado',
    ];

    protected $casts = [
        'fecha_apertura'       => 'datetime',
        'fecha_cierre'         => 'datetime',
        'monto_inicial'        => 'decimal:2',
        'monto_final'          => 'decimal:2',
        'ventas_efectivo'      => 'decimal:2',
        'ventas_tarjeta'       => 'decimal:2',
        'ventas_transferencia' => 'decimal:2',
        'ventas_paypal'        => 'decimal:2',
        'ventas_mercadopago' => 'decimal:2',
        'ventas_mercadopago'   => 'decimal:2',
        'diferencia'           => 'decimal:2',
    ];

    // ── Relaciones ─────────────────────────────────────────────────────────────

    public function restaurante()    { return $this->belongsTo(Restaurante::class); }
    public function movimientos()    { return $this->hasMany(CajaMovimientos::class, 'caja_id'); }
    public function usuarioApertura(){ return $this->belongsTo(User::class, 'usuario_apertura_id'); }
    public function usuarioCierre()  { return $this->belongsTo(User::class, 'usuario_cierre_id'); }

    // ── Accessor: total ventas del día (todos los métodos) ─────────────────────
    public function getTotalVentasAttribute(): float
    {
        return (float) (
            $this->ventas_efectivo     +
            $this->ventas_tarjeta      +
            $this->ventas_transferencia+
            $this->ventas_paypal       +
            $this->ventas_mercadopago
        );
    }
    // ── Accessor: total de órdenes del día ─────────────────────────────────────
public function getTotalOrdenesAttribute(): int
{
    return (int) $this->total_ordenes;
}
}