<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToTenant;

class Caja extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

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
        'ventas_paypal',
        'ventas_mercadopago',
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
        'ventas_mercadopago'   => 'decimal:2',
        'diferencia'           => 'decimal:2',
        'total_ordenes'        => 'integer',
    ];

    // ── Relaciones ─────────────────────────────────────────────────────────────
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function movimientos()
    {
        return $this->hasMany(CajaMovimientos::class, 'caja_id');
    }

    public function usuarioApertura()
    {
        return $this->belongsTo(User::class, 'usuario_apertura_id');
    }

    public function usuarioCierre()
    {
        return $this->belongsTo(User::class, 'usuario_cierre_id');
    }

    // ── Accessor: total ventas del día (todos los métodos) ─────────────────────
    public function getTotalVentasAttribute(): float
    {
        return (float) (
            ($this->ventas_efectivo ?? 0) +
            ($this->ventas_tarjeta ?? 0) +
            ($this->ventas_transferencia ?? 0) +
            ($this->ventas_paypal ?? 0) +
            ($this->ventas_mercadopago ?? 0)
        );
    }

    // ── Accessor: total ventas formateado ──────────────────────────────────────
    public function getTotalVentasFormateadoAttribute(): string
    {
        return '$' . number_format($this->getTotalVentasAttribute(), 2);
    }

    // ── Accessor: estado texto ─────────────────────────────────────────────────
    public function getEstadoTextoAttribute(): string
    {
        return $this->estado === 'abierta' ? 'Abierta' : 'Cerrada';
    }

    // ── Accessor: estado color ─────────────────────────────────────────────────
    public function getEstadoColorAttribute(): string
    {
        return $this->estado === 'abierta' ? 'green' : 'gray';
    }

    // ── Scopes ─────────────────────────────────────────────────────────────────
    public function scopeAbiertas($query)
    {
        return $query->where('estado', 'abierta')->whereNull('fecha_cierre');
    }

    public function scopeCerradas($query)
    {
        return $query->where('estado', 'cerrada')->whereNotNull('fecha_cierre');
    }

    public function scopeDelDia($query, $fecha = null)
    {
        $fecha = $fecha ?? now()->format('Y-m-d');
        return $query->whereDate('fecha_apertura', $fecha);
    }

    public function scopeDelRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    // ── Métodos útiles ─────────────────────────────────────────────────────────
    
    /**
     * Verificar si la caja está abierta
     */
    public function isAbierta(): bool
    {
        return $this->estado === 'abierta' && is_null($this->fecha_cierre);
    }

    /**
     * Verificar si la caja está cerrada
     */
    public function isCerrada(): bool
    {
        return $this->estado === 'cerrada' || !is_null($this->fecha_cierre);
    }

    /**
     * Calcular efectivo esperado en caja
     */
    public function calcularEfectivoEsperado(): float
    {
        $ingresosManuales = $this->movimientos()->where('tipo', 'ingreso')->sum('monto');
        $egresosManuales = $this->movimientos()->where('tipo', 'egreso')->sum('monto');
        
        return (float) (
            ($this->monto_inicial ?? 0) +
            ($this->ventas_efectivo ?? 0) +
            $ingresosManuales -
            $egresosManuales
        );
    }
}