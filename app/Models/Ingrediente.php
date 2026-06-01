<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToTenant;

class Ingrediente extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

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

public function movimientos()
{
    return $this->hasMany(IngredienteMovimiento::class)->latest();
}

    /**
     * Productos que usan este ingrediente
     */
    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'ingredientes_de_productos')
            ->withPivot('cantidad')
            ->withTimestamps();
    }

    /**
     * Recalcula el stock mínimo del ingrediente basándose en los stocks mínimos de los productos que lo usan.
     */
    public function recalcularStockMinimoDesdeProductos()
    {
        // Usamos DB directa para evitar interferencias de scopes o soft-deletes en la relación Eloquent
        $productosAsociados = \Illuminate\Support\Facades\DB::table('ingredientes_de_productos')
            ->join('productos', 'ingredientes_de_productos.producto_id', '=', 'productos.id')
            ->where('ingredientes_de_productos.ingrediente_id', $this->id)
            ->where('productos.activo', true)
            ->whereNull('productos.deleted_at')
            ->select('productos.stock_minimo', 'ingredientes_de_productos.cantidad')
            ->get();

        $stockMinimoCalculado = 0;

        foreach ($productosAsociados as $prod) {
            $cantidadReceta = (float) ($prod->cantidad ?? 0);
            $stockMinimoProducto = (float) ($prod->stock_minimo ?? 0);
            
            if ($cantidadReceta > 0 && $stockMinimoProducto > 0) {
                $stockMinimoCalculado += ($cantidadReceta * $stockMinimoProducto);
            }
        }

        $this->stock_minimo = $stockMinimoCalculado;
        $this->save();

        return $stockMinimoCalculado;
    }

    /**
     * Atributo para saber si el stock es bajo
     */
    public function getBajoStockAttribute()
    {
        return $this->stock_actual <= $this->stock_minimo;
    }

    /**
     * Atributo para el costo total del stock actual
     */
    public function getCostoTotalStockAttribute()
    {
        return $this->costo_unitario * $this->stock_actual;
    }
    
}
