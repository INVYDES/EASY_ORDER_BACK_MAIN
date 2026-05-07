<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nomina extends Model
{
    use SoftDeletes;

    // Constantes de estados
    const ESTADO_PENDIENTE = 'PENDIENTE';
    const ESTADO_PAGADA = 'PAGADA';
    const ESTADO_ANULADA = 'ANULADA';

    public static $estados = [
        self::ESTADO_PENDIENTE => 'Pendiente',
        self::ESTADO_PAGADA => 'Pagada',
        self::ESTADO_ANULADA => 'Anulada',
    ];

    protected $table = 'nominas';

    protected $fillable = [
        'user_id',
        'restaurante_id',
        'periodo_inicio',
        'periodo_fin',
        'horas_totales',
        'salario_base',
        'valor_hora',
        'pago_horas',
        'comision_ventas',
        'bonos',
        'descuentos',
        'pago_total',
        'estado',
        'fecha_pago',
        'metodo_pago',
        'referencia_pago',
        'observaciones',
    ];

    protected $casts = [
        'periodo_inicio' => 'date',
        'periodo_fin' => 'date',
        'fecha_pago' => 'date',
        'horas_totales' => 'decimal:2',
        'salario_base' => 'decimal:2',
        'valor_hora' => 'decimal:2',
        'pago_horas' => 'decimal:2',
        'comision_ventas' => 'decimal:2',
        'bonos' => 'decimal:2',
        'descuentos' => 'decimal:2',
        'pago_total' => 'decimal:2',
    ];

    protected $appends = [
        'estado_nombre',
        'periodo_formato',
    ];

    // ========== RELACIONES ==========
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function detalles()
    {
        return $this->hasMany(NominaDetalle::class);
    }

    // ========== SCOPES ==========
    
    public function scopePendientes($query)
    {
        return $query->where('estado', self::ESTADO_PENDIENTE);
    }

    public function scopePagadas($query)
    {
        return $query->where('estado', self::ESTADO_PAGADA);
    }

    public function scopeAnuladas($query)
    {
        return $query->where('estado', self::ESTADO_ANULADA);
    }

    public function scopePorPeriodo($query, $inicio, $fin)
    {
        return $query->where(function($q) use ($inicio, $fin) {
            $q->whereBetween('periodo_inicio', [$inicio, $fin])
              ->orWhereBetween('periodo_fin', [$inicio, $fin]);
        });
    }

    public function scopePorEmpleado($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopePorRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    // ========== ACCESORS ==========
    
    public function getEstadoNombreAttribute()
    {
        return self::$estados[$this->estado] ?? $this->estado;
    }

    public function getPeriodoFormatoAttribute()
    {
        return $this->periodo_inicio->format('d/m/Y') . ' - ' . $this->periodo_fin->format('d/m/Y');
    }

    public function getEstadoBadgeColorAttribute()
    {
        return match ($this->estado) {
            self::ESTADO_PENDIENTE => 'warning',
            self::ESTADO_PAGADA => 'success',
            self::ESTADO_ANULADA => 'danger',
            default => 'secondary',
        };
    }

    // ========== MUTATORS ==========
    
    public function setPagoTotalAttribute($value)
    {
        $this->attributes['pago_total'] = round($value, 2);
    }

    // ========== MÉTODOS ÚTILES ==========
    
    /**
     * Marcar nómina como pagada
     */
    public function marcarComoPagada($metodoPago = null, $referencia = null)
    {
        $this->estado = self::ESTADO_PAGADA;
        $this->fecha_pago = now();
        
        if ($metodoPago) {
            $this->metodo_pago = $metodoPago;
        }
        if ($referencia) {
            $this->referencia_pago = $referencia;
        }
        
        return $this->save();
    }

    /**
     * Marcar nómina como anulada
     */
    public function marcarComoAnulada($motivo = null)
    {
        $this->estado = self::ESTADO_ANULADA;
        
        if ($motivo) {
            $this->observaciones = ($this->observaciones ? $this->observaciones . "\n" : '') . "Anulada: " . $motivo;
        }
        
        return $this->save();
    }

    /**
     * Verificar si la nómina está pendiente
     */
    public function isPendiente()
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /**
     * Verificar si la nómina está pagada
     */
    public function isPagada()
    {
        return $this->estado === self::ESTADO_PAGADA;
    }

    /**
     * Verificar si la nómina está anulada
     */
    public function isAnulada()
    {
        return $this->estado === self::ESTADO_ANULADA;
    }

    /**
     * Recalcular pago total basado en sus componentes
     */
    public function recalcularPagoTotal()
    {
        $this->pago_total = round(
            $this->salario_base + 
            $this->comision_ventas + 
            $this->bonos - 
            $this->descuentos,
            2
        );
        
        return $this;
    }
}