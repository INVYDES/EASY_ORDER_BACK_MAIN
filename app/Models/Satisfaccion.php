<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Satisfaccion extends Model
{
    protected $table = 'satisfacciones';

    protected $fillable = [
        'orden_id',
        'user_id',
        'restaurante_id',
        'calificacion',
        'comentario',
    ];

    public function orden()
    {
        return $this->belongsTo(Orden::class);
    }

    public function mesero()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}