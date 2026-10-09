<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImpresoraNube extends Model
{
    protected $table = 'impresoras_nube';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'modelo',
        'token',
        'formato',
        'is_active',
        'configuracion',
        'last_polling_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'configuracion' => 'array',
        'last_polling_at' => 'datetime',
    ];

    public function jobs()
    {
        return $this->hasMany(PrintJob::class, 'impresora_id');
    }
}
