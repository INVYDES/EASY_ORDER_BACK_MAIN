<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToTenant;

class InsumoPreparado extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'insumos_preparados';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'unidad',
        'costo_unitario',
        'stock_actual',
        'stock_minimo',
        'vida_util_dias',
        'activo',
    ];

    protected $casts = [
        'costo_unitario' => 'decimal:4',
        'stock_actual' => 'decimal:3',
        'stock_minimo' => 'decimal:3',
        'vida_util_dias' => 'integer',
        'activo' => 'boolean',
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function receta()
    {
        return $this->belongsToMany(Ingrediente::class, 'insumo_preparado_receta')
            ->withPivot('cantidad')
            ->withTimestamps();
    }

    public function movimientos()
    {
        return $this->hasMany(InsumoPreparadoMovimiento::class, 'insumo_preparado_id')->latest();
    }

    public function getBajoStockAttribute()
    {
        return $this->stock_actual <= $this->stock_minimo;
    }

    public function getCostoTotalStockAttribute()
    {
        return $this->costo_unitario * $this->stock_actual;
    }
}
