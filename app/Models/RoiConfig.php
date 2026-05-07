<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoiConfig extends Model
{
    protected $table = 'roi_config';

    protected $fillable = [
        'restaurante_id',
        'inversion_inicial',
        'utilidad_objetivo',
        'gasto_renta',
        'gasto_servicios',
        'gasto_software',
        'gasto_marketing',
    ];

    protected $casts = [
        'inversion_inicial' => 'decimal:2',
        'utilidad_objetivo' => 'decimal:2',
        'gasto_renta'       => 'decimal:2',
        'gasto_servicios'   => 'decimal:2',
        'gasto_software'    => 'decimal:2',
        'gasto_marketing'   => 'decimal:2',
    ];

    /**
     * Relación con el restaurante
     */
    public function restaurante(): BelongsTo
    {
        return $this->belongsTo(Restaurante::class);
    }

    /**
     * Calcular gastos operativos totales
     */
    public function getGastosOperativosTotalesAttribute(): float
    {
        return $this->gasto_renta + 
               $this->gasto_servicios + 
               $this->gasto_software + 
               $this->gasto_marketing;
    }

    /**
     * Verificar si la configuración está completa
     */
    public function isCompleta(): bool
    {
        return $this->inversion_inicial > 0 && 
               $this->utilidad_objetivo > 0;
    }
}