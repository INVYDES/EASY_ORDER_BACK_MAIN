<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Producto extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'productos';

    protected $fillable = [
        'restaurante_id',
        'categoria_id',
        'nombre',
        'descripcion',
        'precio',
        'stock',
        'stock_minimo',
        'activo',
        'imagen'  // 👈 CAMPO PARA LA IMAGEN
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'activo' => 'boolean',
        'stock' => 'integer',
        'stock_minimo' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    protected $attributes = [
        'stock' => 0,
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
        return $this->belongsToMany(Ingrediente::class, 'ingredientes_de_productos')
                    ->withPivot('cantidad')
                    ->withTimestamps();
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
            $ruta = storage_path('app/public/productos/' . $this->imagen);
            if (file_exists($ruta)) {
                return unlink($ruta);
            }
        }
        return false;
    }

    public function getRutaImagenAttribute()
    {
        if ($this->imagen) {
            return storage_path('app/public/productos/' . $this->imagen);
        }
        return null;
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
                    $rutaAnterior = storage_path('app/public/productos/' . $imagenAnterior);
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