<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
        'inversion_inicial' => 'float',
        'utilidad_objetivo' => 'float',
        'gasto_renta'       => 'float',
        'gasto_servicios'   => 'float',
        'gasto_software'    => 'float',
        'gasto_marketing'   => 'float',
    ];
}