<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToTenant;

class Gasto extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'gastos';

    protected $fillable = [
        'restaurante_id',
        'user_id',
        'concepto',
        'categoria',
        'monto',
        'fecha',
        'notas'
    ];

    protected $casts = [
        'monto' => 'decimal:2',
        'fecha' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * RELACIONES
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function usuario()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * SCOPES
     */
    public function scopeDelRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    public function scopeDeMes($query, $mes, $anio)
    {
        return $query->whereMonth('fecha', $mes)->whereYear('fecha', $anio);
    }
}