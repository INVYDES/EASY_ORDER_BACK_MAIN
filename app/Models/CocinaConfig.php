<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CocinaConfig extends Model
{
    protected $table = 'cocina_configs';

    protected $fillable = [
        'restaurante_id',
        'wait_times_config',
    ];

    protected $casts = [
        'wait_times_config' => 'array',
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }
}
