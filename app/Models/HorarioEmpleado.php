<?php
// app/Models/HorarioEmpleado.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorarioEmpleado extends Model
{
    protected $table = 'horarios_empleados';

    protected $fillable = [
        'user_id',
        'restaurante_id',
        'dia_semana',
        'hora_entrada',
        'hora_salida',
        'activo',
    ];

    protected $casts = [
        'dia_semana' => 'integer',
        'activo' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function restaurante(): BelongsTo
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function getHorasTrabajadasAttribute(): float
    {
        $entrada = \Carbon\Carbon::parse($this->hora_entrada);
        $salida = \Carbon\Carbon::parse($this->hora_salida);
        return $entrada->diffInMinutes($salida) / 60;
    }
}