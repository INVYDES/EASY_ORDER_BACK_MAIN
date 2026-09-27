<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

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

    /**
     * Ingredientes del producto.
     *
     * ACTUALIZADO (verificado en railway_full_dump.sql, 2026-09-18): el pivot
     * `ingredientes_de_productos` YA tiene componente_type,
     * cantidad_pequeno/cantidad_mediano/cantidad_grande y cantidades_por_tamano, y
     * su FK hacia `ingredientes` ya no existe (índice compuesto
     * prod_comp_type_idx en su lugar). El comentario anterior (fix 2026-08-07)
     * se basaba en los dumps de julio, donde esas columnas no estaban.
     *
     * La relación sigue leyendo la cantidad base (`cantidad`); el desglose por
     * tamaño existe ya en el esquema pero no se usa aquí: los precios y stock por
     * tamaño salen de `tamanos_personalizados`.
     */
    public function ingredientes()
    {
        return $this->belongsToMany(Ingrediente::class, 'ingredientes_de_productos', 'producto_id', 'ingrediente_id')
                    ->withPivot('cantidad', 'tamano_id')
                    ->withTimestamps();
    }

    /**
     * Insumos preparados del producto.
     *
     * ACTUALIZADO (2026-09): la columna `componente_type` del pivot ya existe, así
     * que los insumos preparados se distinguen en la MISMA tabla con
     * componente_type = 'insumo_preparado'. Como además se eliminó la FK de
     * `ingrediente_id` hacia `ingredientes`, esa columna puede referenciar un
     * insumo preparado (no hay columna insumo_preparado_id aparte).
     *
     * La tabla `insumos_preparados` TODAVÍA NO existe en la base de datos actual:
     * el esquema la define en la migración
     * 2026_06_16_010000_create_insumos_preparados_table, pero solo aparece en
     * core_res_backup_previo.sql, no en railway_full_dump.sql (2026-09-18).
     * Consultarla rompería /api/productos, que carga esta relación vía
     * getTodosLosComponentesAttribute() y recalcularStockDesdeIngredientes(), así
     * que la reactivación queda POSPUESTA a propósito: mientras falte la tabla (o
     * la columna) se devuelve una relación vacía que no la toca. En cuanto el
     * esquema esté completo, la relación se activa sola.
     */
    public function insumosPreparados()
    {
        if (!$this->esquemaInsumosPreparadosListo()) {
            return $this->belongsToMany(Ingrediente::class, 'ingredientes_de_productos', 'producto_id', 'ingrediente_id')
                        ->withPivot('cantidad', 'tamano_id')
                        ->whereRaw('1 = 0');
        }

        return $this->belongsToMany(InsumoPreparado::class, 'ingredientes_de_productos', 'producto_id', 'ingrediente_id')
                    ->withPivot('cantidad', 'tamano_id', 'componente_type')
                    ->wherePivot('componente_type', 'insumo_preparado')
                    ->withTimestamps();
    }

    /** Resultado memoizado de la verificación de esquema (una consulta por request). */
    protected static ?bool $esquemaInsumosPreparadosListo = null;

    /**
     * ¿El esquema soporta insumos preparados?
     *
     * Hacen falta las dos cosas: la tabla `insumos_preparados` y la columna
     * `componente_type` del pivot (el backup de julio tenía la tabla pero no la
     * columna; el de septiembre al revés). Las migraciones que las crean son
     * 2026_06_16_010000_create_insumos_preparados_table y
     * 2026_07_06_000001_add_componente_type_to_ingredientes_de_productos.
     */
    protected function esquemaInsumosPreparadosListo(): bool
    {
        if (static::$esquemaInsumosPreparadosListo === null) {
            static::$esquemaInsumosPreparadosListo = Schema::hasTable('insumos_preparados')
                && Schema::hasColumn('ingredientes_de_productos', 'componente_type');
        }

        return static::$esquemaInsumosPreparadosListo;
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
            if (filter_var($this->imagen, FILTER_VALIDATE_URL)) {
                return $this->imagen;
            }
            return \Illuminate\Support\Facades\Storage::disk(config('filesystems.images_disk'))->url($this->imagen);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->nombre) . '&color=7F9CF5&background=EBF4FF';
    }

    public function getImagenDataAttribute()
    {
        $disk = config('filesystems.images_disk');
        return [
            'nombre' => $this->imagen,
            'url' => $this->imagen_url,
            'existe' => !is_null($this->imagen) && $this->imagen !== '',
            'ruta_completa' => $this->imagen
                ? ($disk === 'public' ? storage_path('app/public/' . $this->imagen) : $this->imagen_url)
                : null
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
            $disk = config('filesystems.images_disk');
            if ($disk === 'public') {
                $ruta = storage_path('app/public/' . $this->imagen);
                if (file_exists($ruta)) {
                    return unlink($ruta);
                }
            } else {
                \Illuminate\Support\Facades\Storage::disk($disk)->delete($this->imagen);
                return true;
            }
        }
        return false;
    }

    public function getRutaImagenAttribute()
    {
        if ($this->imagen) {
            $disk = config('filesystems.images_disk');
            if ($disk === 'public') {
                return storage_path('app/public/' . $this->imagen);
            }
            return $this->imagen_url;
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
     *
     * ACTUALIZADO (2026-09): las columnas cantidad_pequeno/mediano/grande ya
     * existen en el pivot, pero se sigue leyendo la cantidad base. Para cantidad
     * distinta por tamaño hay dos vías ya presentes en el esquema: esas columnas o
     * `tamano_id` (una fila por producto + tamaño + componente).
     */
    private function getCantidadReceta($componente, string $key): float
    {
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
                    $disk = config('filesystems.images_disk');
                    if ($disk === 'public') {
                        $rutaAnterior = storage_path('app/public/' . $imagenAnterior);
                        if (file_exists($rutaAnterior)) {
                            unlink($rutaAnterior);
                        }
                    } else {
                        \Illuminate\Support\Facades\Storage::disk($disk)->delete($imagenAnterior);
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