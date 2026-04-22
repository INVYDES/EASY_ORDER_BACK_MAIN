<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Cliente extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'apellido',
        'email',
        'telefono',
        'direccion',
        'fecha_registro',
        'total_compras',
        'gasto_total',
        'notas',
        'activo'
    ];

    protected $casts = [
        'fecha_registro' => 'date',
        'total_compras' => 'integer',
        'gasto_total' => 'decimal:2',
        'activo' => 'boolean'
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class);
    }

    public function getNombreCompletoAttribute()
    {
        return trim($this->nombre . ' ' . $this->apellido);
    }
}