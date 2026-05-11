<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class Ticket extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $fillable = [
        'restaurante_id',
        'user_id',
        'usuario_nombre',
        'contacto',
        'mensaje',
        'clasificacion',
        'prioridad',
        'estado',
        'respuesta_ia',
        'notas_admin',
        'metadata'
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }
}
