<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nomina extends Model
{
    use SoftDeletes;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getEstadoNombreAttribute()
    {
        $estados = [
            'PENDIENTE' => 'Pendiente',
            'PAGADA' => 'Pagada',
            'ANULADA' => 'Anulada',
        ];

        return $estados[$this->estado] ?? $this->estado;
    }

    public function getPeriodoFormatoAttribute()
    {
        return $this->periodo_inicio->format('d/m/Y') . ' - ' . $this->periodo_fin->format('d/m/Y');
    }
}
