<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MesaMesero extends Model
{
    protected $table = 'mesas_meseros';

    protected $fillable = [
        'user_id',
        'restaurante_id',
        'propietario_id',
        'rol_id',
        'numero_mesa'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function propietario()
    {
        return $this->belongsTo(Propietario::class);
    }
}
