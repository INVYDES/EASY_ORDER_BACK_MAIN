<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SesionEmpleado extends Model
{
    protected $table = 'sesiones_empleados';

    protected $fillable = [
        'user_id',
        'restaurante_id',
        'propietario_id',
        'hora_entrada',
        'hora_salida',
    ];

    protected $casts = [
        'hora_entrada' => 'datetime',
        'hora_salida' => 'datetime',
    ];

    protected function serializeDate(\DateTimeInterface $date)
    {
        return $date->format('Y-m-d H:i:s');
    }

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
