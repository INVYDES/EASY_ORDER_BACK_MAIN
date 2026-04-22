<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class IngredienteMovimiento extends Model
{
    use HasFactory;

    protected $table = 'ingrediente_movimientos';

    protected $fillable = [
        'ingrediente_id',
        'user_id',
        'tipo',               // 'entrada', 'salida', 'ajuste'
        'cantidad_anterior',
        'cantidad_movimiento',
        'cantidad_nueva',
        'motivo',
        'orden_id',           // ← Para vincular con órdenes de venta
    ];

    protected $casts = [
        'cantidad_anterior'   => 'decimal:3',
        'cantidad_movimiento' => 'decimal:3',
        'cantidad_nueva'      => 'decimal:3',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    // ─── CONSTANTES ──────────────────────────────────────────
    const TIPO_ENTRADA = 'entrada';
    const TIPO_SALIDA  = 'salida';
    const TIPO_AJUSTE  = 'ajuste';

    // ─── RELACIONES ──────────────────────────────────────────
    
    /**
     * Relación con el ingrediente afectado
     */
    public function ingrediente()
    {
        return $this->belongsTo(Ingrediente::class);
    }

    /**
     * Relación con el usuario que realizó el movimiento
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con la orden (si el movimiento fue por venta)
     */
    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    // ─── ACCESSORS ────────────────────────────────────────────

    /**
     * Obtener etiqueta legible del tipo
     */
    public function getTipoLabelAttribute(): string
    {
        return match($this->tipo) {
            self::TIPO_ENTRADA => '📥 Entrada',
            self::TIPO_SALIDA  => '📤 Salida',
            self::TIPO_AJUSTE  => '⚙️ Ajuste',
            default            => $this->tipo,
        };
    }

    /**
     * Obtener clase CSS para el tipo
     */
    public function getTipoColorAttribute(): string
    {
        return match($this->tipo) {
            self::TIPO_ENTRADA => 'text-green-600 bg-green-100',
            self::TIPO_SALIDA  => 'text-red-600 bg-red-100',
            self::TIPO_AJUSTE  => 'text-yellow-600 bg-yellow-100',
            default            => 'text-gray-600 bg-gray-100',
        };
    }

    /**
     * Obtener signo del movimiento
     */
    public function getSignoAttribute(): string
    {
        return match($this->tipo) {
            self::TIPO_ENTRADA => '+',
            self::TIPO_SALIDA  => '-',
            self::TIPO_AJUSTE  => '±',
            default            => '',
        };
    }

    // ─── SCOPES ───────────────────────────────────────────────

    /**
     * Scope para movimientos de entrada
     */
    public function scopeEntradas($query)
    {
        return $query->where('tipo', self::TIPO_ENTRADA);
    }

    /**
     * Scope para movimientos de salida
     */
    public function scopeSalidas($query)
    {
        return $query->where('tipo', self::TIPO_SALIDA);
    }

    /**
     * Scope para movimientos de ajuste
     */
    public function scopeAjustes($query)
    {
        return $query->where('tipo', self::TIPO_AJUSTE);
    }

    /**
     * Scope para filtrar por rango de fechas
     */
    public function scopeEntreFechas($query, $inicio, $fin)
    {
        return $query->whereBetween('created_at', [$inicio, $fin]);
    }

    // ─── MÉTODOS DE UTILIDAD ─────────────────────────────────

    /**
     * Verificar si es una entrada
     */
    public function esEntrada(): bool
    {
        return $this->tipo === self::TIPO_ENTRADA;
    }

    /**
     * Verificar si es una salida
     */
    public function esSalida(): bool
    {
        return $this->tipo === self::TIPO_SALIDA;
    }

    /**
     * Verificar si es un ajuste
     */
    public function esAjuste(): bool
    {
        return $this->tipo === self::TIPO_AJUSTE;
    }

    /**
     * Obtener el impacto neto en el inventario
     */
    public function getImpactoNetoAttribute(): float
    {
        return match($this->tipo) {
            self::TIPO_ENTRADA => $this->cantidad_movimiento,
            self::TIPO_SALIDA  => -$this->cantidad_movimiento,
            self::TIPO_AJUSTE  => $this->cantidad_nueva - $this->cantidad_anterior,
            default            => 0,
        };
    }

    // ─── EVENTOS ──────────────────────────────────────────────

    protected static function booted()
    {
        static::creating(function ($movimiento) {
            // Validar consistencia de cantidades
            if ($movimiento->tipo === self::TIPO_SALIDA && $movimiento->cantidad_movimiento > $movimiento->cantidad_anterior) {
                throw new \Exception('No se puede retirar más stock del disponible');
            }
        });
    }
    
}