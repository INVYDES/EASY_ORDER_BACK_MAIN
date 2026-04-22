<?php
// app/Models/Oferta.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Oferta extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ofertas';

    protected $fillable = [
        'restaurante_id',
        'titulo',
        'descripcion',
        'descripcion_corta',
        'tipo',
        'descuento_porcentaje',
        'precio_especial',
        'dias_semana',
        'icono',
        'activo'
    ];

    protected $casts = [
        'dias_semana' => 'array',
        'activo' => 'boolean',
        'descuento_porcentaje' => 'decimal:2',
        'precio_especial' => 'decimal:2'
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'oferta_producto')
            ->withTimestamps();
    }
}