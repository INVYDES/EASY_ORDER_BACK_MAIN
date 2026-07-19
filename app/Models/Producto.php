<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\BelongsToTenant;

class Producto extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $table = 'productos';

    protected $fillable = [
        'restaurante_id',
        'categoria_id',
        'nombre',
        'descripcion',
        'precio',
        'costo',
        'stock',
        'tiene_tamanos',
        'tamanos_personalizados',
        'stock_minimo',
        'minutos_produccion',
        'nomina_diaria',
        'activo',
        'imagen'
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'precio_mediano' => 'decimal:2',
        'precio_grande' => 'decimal:2',
        'costo' => 'decimal:2',
        'activo' => 'boolean',
        'tiene_tamanos' => 'boolean',
        'tamanos_personalizados' => 'array',
        'stock' => 'decimal:2',
        'stock_pequeno' => 'decimal:2',
        'stock_mediano' => 'decimal:2',
        'stock_grande' => 'decimal:2',
        'stock_minimo' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'stock' => 0,
        'tiene_tamanos' => false,
        'stock_pequeno' => 0,
        'stock_mediano' => 0,
        'stock_grande' => 0,
        'stock_minimo' => 5,
        'activo' => true
    ];

    /**
     * RELACIONES
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }

    public function ordenDetalles()
    {
        return $this->hasMany(OrdenDetalle::class);
    }

    public function ingredientes()
    {
        return $this->belongsToMany(Ingrediente::class, 'ingredientes_de_productos', 'producto_id', 'ingrediente_id')
                    ->wherePivot('componente_type', 'ingrediente')
                    ->withPivot('cantidad', 'cantidad_pequeno', 'cantidad_mediano', 'cantidad_grande', 'componente_type')
                    ->withTimestamps();
    }

    public function insumosPreparados()
    {
        return $this->belongsToMany(InsumoPreparado::class, 'ingredientes_de_productos', 'producto_id', 'ingrediente_id')
                    ->wherePivot('componente_type', 'insumo_preparado')
                    ->withPivot('cantidad', 'cantidad_pequeno', 'cantidad_mediano', 'cantidad_grande', 'componente_type')
                    ->withTimestamps();
    }

    public function getTodosLosComponentesAttribute()
    {
        if (!$this->relationLoaded('ingredientes')) {
            $this->load('ingredientes');
        }
        if (!$this->relationLoaded('insumosPreparados')) {
            $this->load('insumosPreparados');
        }

        return $this->ingredientes
            ->concat($this->insumosPreparados);
    }

    public function ingredienteMovimientos()
{
    return $this->hasMany(IngredienteMovimiento::class);
}
    /**
     * SCOPES (ÁMBITOS)
     */
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeInactivos($query)
    {
        return $query->where('activo', false);
    }

    public function scopeConStock($query)
    {
        return $query->where('stock', '>', 0);
    }

    public function scopeSinStock($query)
    {
        return $query->where('stock', '<=', 0);
    }

    public function scopeBajoStock($query)
    {
        return $query->whereColumn('stock', '<=', 'stock_minimo')
                     ->where('stock', '>', 0);
    }

    public function scopeDelRestaurante($query, $restauranteId)
    {
        return $query->where('restaurante_id', $restauranteId);
    }

    public function scopeDeCategoria($query, $categoriaId)
    {
        return $query->where('categoria_id', $categoriaId);
    }

    public function scopeBuscar($query, $termino)
    {
        return $query->where(function($q) use ($termino) {
            $q->where('nombre', 'LIKE', "%{$termino}%")
              ->orWhere('descripcion', 'LIKE', "%{$termino}%");
        });
    }

    public function scopePrecioEntre($query, $min, $max)
    {
        return $query->whereBetween('precio', [$min, $max]);
    }

    /**
     * ATRIBUTOS CALCULADOS (APPENDS)
     */
    protected $appends = [
        'bajo_stock',
        'agotado',
        'estado_stock',
        'precio_formateado',
        'imagen_url',      // 👈 NUEVO
        'imagen_data',      // 👈 NUEVO
        'tiene_imagen'      // 👈 NUEVO
    ];

    /**
     * ACCESORS (GETTERS)
     */
    public function getBajoStockAttribute(): bool
    {
        return $this->stock <= $this->stock_minimo && $this->stock > 0;
    }

    public function getAgotadoAttribute(): bool
    {
        return $this->stock <= 0;
    }

    public function getEstadoStockAttribute(): string
    {
        if ($this->stock <= 0) return 'agotado';
        if ($this->stock <= $this->stock_minimo) return 'bajo';
        return 'normal';
    }

    public function getPrecioFormateadoAttribute(): string
    {
        return '$' . number_format($this->precio, 2);
    }

    private function getPrecioFromTamano(string $key, $default): float
    {
        if (!$this->tiene_tamanos || empty($this->tamanos_personalizados)) {
            return (float) $default;
        }
        foreach ((array) $this->tamanos_personalizados as $t) {
            if (($t['key'] ?? '') === $key) {
                $p = $t['precio'] ?? null;
                if ($p !== null && (float) $p > 0) return (float) $p;
            }
        }
        return (float) $default;
    }

    private function getStockFromTamano(string $key, $default): int
    {
        if (!$this->tiene_tamanos || empty($this->tamanos_personalizados)) {
            return (int) $default;
        }
        foreach ((array) $this->tamanos_personalizados as $t) {
            if (($t['key'] ?? '') === $key) {
                $s = $t['stock'] ?? null;
                if ($s !== null) return (int) $s;
            }
        }
        return (int) $default;
    }

    public function getPrecioMedianoAttribute($value): float
    {
        return $this->getPrecioFromTamano('mediano', $value);
    }

    public function getPrecioGrandeAttribute($value): float
    {
        return $this->getPrecioFromTamano('grande', $value);
    }

    public function getStockPequenoAttribute($value): int
    {
        return $this->getStockFromTamano('pequeno', $value);
    }

    public function getStockMedianoAttribute($value): int
    {
        return $this->getStockFromTamano('mediano', $value);
    }

    public function getStockGrandeAttribute($value): int
    {
        return $this->getStockFromTamano('grande', $value);
    }

    /**
     * ACCESORS PARA IMAGEN - 🖼️ NUEVOS
     */
    public function getImagenUrlAttribute()
    {
        if ($this->imagen) {
            // Si es una URL completa (para imágenes externas)
            if (filter_var($this->imagen, FILTER_VALIDATE_URL)) {
                return $this->imagen;
            }
            // Si es solo el nombre del archivo (guardado en storage)
            // Quitamos 'productos/' adicional porque $this->imagen ya lo incluye
            return asset('storage/' . $this->imagen);
        }
        
        // Imagen por defecto (apuntando a una ruta que no de error o un placeholder)
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->nombre) . '&color=7F9CF5&background=EBF4FF';
    }

    public function getImagenDataAttribute()
    {
        return [
            'nombre' => $this->imagen,
            'url' => $this->imagen_url,
            'existe' => !is_null($this->imagen) && $this->imagen !== '',
            'ruta_completa' => $this->imagen ? storage_path('app/public/productos/' . $this->imagen) : null
        ];
    }

    public function getTieneImagenAttribute(): bool
    {
        return !is_null($this->imagen) && $this->imagen !== '';
    }

    /**
     * MÉTODOS AUXILIARES PARA IMAGEN
     */
    public function eliminarImagenFisica()
    {
        if ($this->imagen) {
            $ruta = storage_path('app/public/' . $this->imagen);
            if (file_exists($ruta)) {
                return unlink($ruta);
            }
        }
        return false;
    }

    public function getRutaImagenAttribute()
    {
        if ($this->imagen) {
            return storage_path('app/public/' . $this->imagen);
        }
        return null;
    }

    /**
     * Recalcula el stock del producto basándose en el componente más limitante.
     * Soporta ingredientes crudos, insumos preparados y sub-productos.
     * Para productos con tamaños, itera dinámicamente sobre tamanos_personalizados.
     */
    public function recalcularStockDesdeIngredientes()
    {
        $this->load([
            'ingredientes' => fn($q) => $q->withoutGlobalScope(\App\Scopes\TenantScope::class),
            'insumosPreparados' => fn($q) => $q->withoutGlobalScope(\App\Scopes\TenantScope::class),
        ]);

        $todos = collect()
            ->merge($this->ingredientes)
            ->merge($this->insumosPreparados);

        if ($todos->isEmpty()) {
            return $this->stock;
        }

        $getStock = fn($item) => $item->stock_actual;

        if ($this->tiene_tamanos && !empty($this->tamanos_personalizados)) {
            $tamanosActualizados = [];
            $stockTotal = 0;

            foreach ((array) $this->tamanos_personalizados as $t) {
                $key = $t['key'] ?? '';
                if (!$key) continue;

                $minimo = $todos->map(fn($c) => $this->calcularUnidadesPorTamano($c, $key, $getStock))->min();
                $stockTamano = max(0, (int) ($minimo === PHP_INT_MAX ? 0 : $minimo));

                $tamanosActualizados[] = array_merge($t, ['stock' => $stockTamano]);
                $stockTotal += $stockTamano;
            }

            $this->tamanos_personalizados = $tamanosActualizados;
            $this->stock = $stockTotal;
        } else {
            $minimo = $todos->map(fn($c) => $this->calcularUnidadesPorTamano($c, '', $getStock))->min();
            $this->stock = max(0, (int) ($minimo === PHP_INT_MAX ? 0 : $minimo));
        }

        $this->save();
        return $this->stock;
    }

    private function calcularUnidadesPorTamano($componente, string $key, \Closure $getStock): int
    {
        $cantidad = $this->getCantidadReceta($componente, $key);
        if ($cantidad <= 0) return PHP_INT_MAX;
        $stock = $getStock($componente);
        return (int) floor($stock / $cantidad);
    }

    /**
     * Obtiene la cantidad de un componente para un tamaño específico.
     * Mapea 'pequeno'/'mediano'/'grande' a columnas legacy,
     * cualquier otro key usa la columna 'cantidad' (base).
     */
    private function getCantidadReceta($componente, string $key): float
    {
        $legacyMap = ['pequeno' => 'cantidad_pequeno', 'mediano' => 'cantidad_mediano', 'grande' => 'cantidad_grande'];
        $columna = $legacyMap[$key] ?? null;

        if ($columna) {
            return (float) ($componente->pivot->$columna ?? $componente->pivot->cantidad ?? 0);
        }

        return (float) ($componente->pivot->cantidad ?? 0);
    }

    /**
     * BOOT DEL MODELO
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($producto) {
            if (empty($producto->stock_minimo)) {
                $producto->stock_minimo = 5;
            }
        });

        // Al eliminar el producto, eliminar también la imagen física
        static::deleting(function ($producto) {
            $producto->eliminarImagenFisica();
        });

        // Al actualizar, si cambia la imagen, eliminar la anterior
        static::updating(function ($producto) {
            if ($producto->isDirty('imagen')) {
                $imagenAnterior = $producto->getOriginal('imagen');
                if ($imagenAnterior) {
                    $rutaAnterior = storage_path('app/public/' . $imagenAnterior);
                    if (file_exists($rutaAnterior)) {
                        unlink($rutaAnterior);
                    }
                }
            }
        });
    }

    /**
     * QUERY LOCAL SCOPES ADICIONALES
     */
    public function scopeConImagen($query)
    {
        return $query->whereNotNull('imagen');
    }

    public function scopeSinImagen($query)
    {
        return $query->whereNull('imagen');
    }
}