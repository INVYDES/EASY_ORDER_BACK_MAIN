<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionNomina extends Model
{
    protected $table = 'configuracion_nomina';

    protected $fillable = [
        'restaurante_id',
        'salario_base_por_defecto',
        'valor_hora_por_defecto',
        'porcentaje_comision_ventas',
        'dias_pago',
        'activo',
    ];

    protected $casts = [
        'salario_base_por_defecto' => 'decimal:2',
        'valor_hora_por_defecto' => 'decimal:2',
        'porcentaje_comision_ventas' => 'decimal:2',
        'activo' => 'boolean',
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getDiasPagoArrayAttribute()
    {
        return explode(',', $this->dias_pago);
    }
}