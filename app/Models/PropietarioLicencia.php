<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class PropietarioLicencia extends Model
{
    use SoftDeletes;

    // ✅ CORREGIDO: nombre exacto de la tabla (sin "s" al final)
    protected $table = 'propietario_licencia';

    protected $fillable = [
        'propietario_id',
        'licencia_id',
        'fecha_inicio',
        'fecha_expiracion',
        'estado',
        // PayPal
        'paypal_subscription_id',
        'paypal_order_id',
        'paypal_customer_id',
        // Mercado Pago
        'mercadopago_payment_id',
        // Pagos
        'monto_pagado',
        'metodo_pago',
        'ultimo_pago_at',
        'proximo_pago_at',
        'auto_renovar',
        // Enterprise
        'es_enterprise',
        'costo_personalizado',
        'cantidad_restaurantes_contratados',
        'precio_personalizado_mensual',
        'precio_personalizado_anual',
        'notas_admin',
        'creado_por_admin_id'
    ];

    protected $casts = [
        'fecha_inicio' => 'datetime',
        'fecha_expiracion' => 'datetime',
        'ultimo_pago_at' => 'datetime',
        'proximo_pago_at' => 'datetime',
        'monto_pagado' => 'decimal:2',
        'costo_personalizado' => 'decimal:2',
        'precio_personalizado_mensual' => 'decimal:2',
        'precio_personalizado_anual' => 'decimal:2',
        'auto_renovar' => 'boolean',
        'es_enterprise' => 'boolean'
    ];

    // ============================================
    // RELACIONES
    // ============================================
    
    public function propietario()
    {
        return $this->belongsTo(Propietario::class);
    }

    public function licencia()
    {
        return $this->belongsTo(Licencia::class);
    }

    public function creadoPorAdmin()
    {
        return $this->belongsTo(User::class, 'creado_por_admin_id');
    }

    // ============================================
    // SCOPES
    // ============================================
    
    public function scopeActivas($query)
    {
        return $query->where('estado', 'ACTIVA')
            ->where('fecha_expiracion', '>', Carbon::now());
    }

    public function scopePendientes($query)
    {
        return $query->where('estado', 'PENDIENTE');
    }

    public function scopeExpiradas($query)
    {
        return $query->where('fecha_expiracion', '<', Carbon::now())
            ->where('estado', '!=', 'EXPIRADA');
    }

    public function scopePorVencer($query, $days = 7)
    {
        return $query->where('estado', 'ACTIVA')
            ->whereBetween('fecha_expiracion', [
                Carbon::now(),
                Carbon::now()->addDays($days)
            ]);
    }

    public function scopeEnterprise($query)
    {
        return $query->where('es_enterprise', true);
    }

    // ============================================
    // MÉTODOS DE UTILIDAD
    // ============================================
    
    public function estaActiva()
    {
        return $this->estado === 'ACTIVA' && 
               $this->fecha_expiracion > Carbon::now();
    }

    public function estaPorVencer($days = 7)
    {
        if (!$this->estaActiva()) return false;
        return $this->fecha_expiracion <= Carbon::now()->addDays($days);
    }

    public function renovar()
    {
        $licencia = $this->licencia;
        
        // Determinar días según el tipo de licencia
        if ($this->es_enterprise) {
            // Para Enterprise, usar la cantidad de meses contratados
            $dias = 30; // por defecto, se puede personalizar
        } else {
            $dias = $licencia->tipo === 'MENSUAL' ? 30 : 365;
        }
        
        $this->update([
            'fecha_inicio' => Carbon::now(),
            'fecha_expiracion' => Carbon::now()->addDays($dias),
            'ultimo_pago_at' => Carbon::now(),
            'proximo_pago_at' => Carbon::now()->addDays($dias),
            'estado' => 'ACTIVA'
        ]);
        
        return $this;
    }

    public function cancelar()
    {
        $this->update([
            'estado' => 'CANCELADA',
            'auto_renovar' => false
        ]);
        
        return $this;
    }

    // ============================================
    // ACCESSORS
    // ============================================
    
    public function getDiasRestantesAttribute()
    {
        if (!$this->fecha_expiracion) return 0;
        return max(0, Carbon::now()->diffInDays($this->fecha_expiracion, false));
    }

    public function getEstadoTextoAttribute()
    {
        $estados = [
            'ACTIVA' => 'Activa',
            'PENDIENTE' => 'Pendiente',
            'CANCELADA' => 'Cancelada',
            'EXPIRADA' => 'Expirada'
        ];
        return $estados[$this->estado] ?? $this->estado;
    }

    public function getMontoPagadoFormateadoAttribute()
    {
        if (!$this->monto_pagado) return 'Pendiente';
        return '$' . number_format($this->monto_pagado, 2);
    }

    public function getPrecioPersonalizadoMensualFormateadoAttribute()
    {
        if (!$this->precio_personalizado_mensual) return 'Cotizar';
        return '$' . number_format($this->precio_personalizado_mensual, 2);
    }

    public function getPrecioPersonalizadoAnualFormateadoAttribute()
    {
        if (!$this->precio_personalizado_anual) return 'Cotizar';
        return '$' . number_format($this->precio_personalizado_anual, 2);
    }
}