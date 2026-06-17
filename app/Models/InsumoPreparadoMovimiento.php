<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class InsumoPreparadoMovimiento extends Model
{
    use HasFactory;

    protected $table = 'insumo_preparado_movimientos';

    protected $fillable = [
        'insumo_preparado_id',
        'user_id',
        'tipo',
        'cantidad_anterior',
        'cantidad_movimiento',
        'cantidad_nueva',
        'motivo',
    ];

    protected $casts = [
        'cantidad_anterior' => 'decimal:3',
        'cantidad_movimiento' => 'decimal:3',
        'cantidad_nueva' => 'decimal:3',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function insumoPreparado()
    {
        return $this->belongsTo(InsumoPreparado::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
