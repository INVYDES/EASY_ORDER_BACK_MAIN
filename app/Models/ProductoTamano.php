<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\BelongsToTenant;

class ProductoTamano extends Model
{
    use HasFactory, BelongsToTenant;

    protected $table = 'producto_tamanos';

    protected $fillable = [
        'restaurante_id',
        'producto_id',
        'nombre',
        'precio',
        'costo',
        'stock',
        'stock_minimo'
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'costo' => 'decimal:4',
        'stock' => 'decimal:2',
        'stock_minimo' => 'decimal:2'
    ];

    public function producto()
    {
        return $this->belongsTo(Producto::class);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function ingredientes()
    {
        return $this->belongsToMany(Ingrediente::class, 'ingredientes_de_productos', 'tamano_id', 'ingrediente_id')
                    ->withPivot('cantidad')
                    ->withTimestamps();
    }

    /**
     * Recalcula el stock de este tamaño basándose en el ingrediente más limitante
     */
    public function recalcularStockDesdeIngredientes()
    {
        $this->load(['ingredientes' => function($query) {
            $query->withoutGlobalScope(\App\Scopes\TenantScope::class);
        }]);

        if ($this->ingredientes->isEmpty()) {
            return $this->stock;
        }

        $unidadesPosibles = $this->ingredientes->map(function($ing) {
            $cantidadNecesaria = $ing->pivot->cantidad ?? 0;
            if ($cantidadNecesaria <= 0) return PHP_INT_MAX;
            return floor($ing->stock_actual / $cantidadNecesaria);
        });

        $nuevoStock = $unidadesPosibles->min();
        if ($nuevoStock === PHP_INT_MAX) $nuevoStock = 0;

        $this->stock = $nuevoStock;
        $this->save();

        return $nuevoStock;
    }
}
