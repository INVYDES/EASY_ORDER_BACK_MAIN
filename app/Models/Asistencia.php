<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asistencia extends Model
{
    use SoftDeletes;

    protected $table = 'asistencias';

    protected $fillable = [
        'user_id',
        'restaurante_id',
        'fecha',
        'hora_entrada',
        'hora_salida',
        'horas_trabajadas',
        'ventas_generadas',
        'tipo_registro',
        'ip_registro',
    ];

    protected $casts = [
        'fecha' => 'date',
        'horas_trabajadas' => 'decimal:2',
        'ventas_generadas' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getFechaFormatoAttribute()
    {
        return $this->fecha->format('d/m/Y');
    }
}
