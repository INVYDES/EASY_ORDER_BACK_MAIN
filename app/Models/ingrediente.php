<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ingrediente extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ingredientes';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'unidad',
        'costo_unitario',
        'stock_actual',
        'stock_minimo',
        'proveedor',
        'activo'
    ];

    protected $casts = [
        'costo_unitario' => 'decimal:4',
        'stock_actual' => 'decimal:3',
        'stock_minimo' => 'decimal:3',
        'activo' => 'boolean',
    ];

    /**
     * Relación con el restaurante (Tenant)
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    /**
     * Movimientos de este ingrediente
     */
    public function movimientos()
    {
        return $this->hasMany(IngredienteMovimiento::class);
    }

    /**
     * Productos que usan este ingrediente
     */
    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'producto_ingrediente')
            ->withPivot('cantidad');
    }

    /**
     * Atributo para saber si el stock es bajo
     */
    public function getBajoStockAttribute()
    {
        return $this->stock_actual <= $this->stock_minimo;
    }
}
