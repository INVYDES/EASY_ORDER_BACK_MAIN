<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categoria extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'categorias';

    protected $fillable = [
        'restaurante_id',
        'nombre',
        'descripcion',
        'color',
        'activo'
    ];

    protected $casts = [
        'activo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'color' => '#3B82F6',
        'activo' => true
    ];

    /**
     * Relación con el restaurante
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    /**
     * Relación con los productos
     */
    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    /**
     * Productos activos de esta categoría
     */
    public function productosActivos()
    {
        return $this->hasMany(Producto::class)->where('activo', true);
    }

    /**
     * Scope para categorías activas
     */
    public function scopeActivas($query)
    {
        return $query->where('activo', true);
    }

    /**
     * Scope para filtrar por restaurante
     */
    public function scopeDelRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    /**
     * Scope para buscar por nombre
     */
    public function scopeBuscar($query, $termino)
    {
        return $query->where('nombre', 'LIKE', "%{$termino}%");
    }

    /**
     * Contar productos de la categoría
     */
    public function getTotalProductosAttribute(): int
    {
        return $this->productos()->count();
    }

    /**
     * Contar productos activos
     */
    public function getProductosActivosAttribute(): int
    {
        return $this->productos()->where('activo', true)->count();
    }

    /**
     * Verificar si tiene productos
     */
    public function getTieneProductosAttribute(): bool
    {
        return $this->productos()->exists();
    }

    /**
     * Obtener color con formato válido para CSS
     */
    public function getColorCssAttribute(): string
    {
        return $this->color ?? '#3B82F6';
    }

    /**
     * Boot del modelo
     */
    protected static function boot()
    {
        parent::boot();

        // Al eliminar una categoría, quitar la referencia de los productos
        static::deleting(function ($categoria) {
            if ($categoria->isForceDeleting()) {
                $categoria->productos()->forceDelete();
            } else {
                $categoria->productos()->update(['categoria_id' => null]);
            }
        });

        // Al restaurar una categoría
        static::restored(function ($categoria) {
            // Opcional: restaurar productos? Depende de la lógica de negocio
        });
    }
}