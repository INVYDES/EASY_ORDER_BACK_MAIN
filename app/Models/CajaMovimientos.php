<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CajaMovimientos extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'caja_movimientos';

    protected $fillable = [
        'caja_id',
        'usuario_id',
        'tipo',
        'monto',
        'descripcion',
        'referencia'
    ];

    protected $casts = [
        'monto' => 'decimal:2'
    ];

    /**
     * Relación con la caja
     */
    public function caja()
    {
        return $this->belongsTo(Caja::class, 'caja_id');
    }

    /**
     * Relación con el usuario que registró el movimiento
     */
    public function usuario()
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}