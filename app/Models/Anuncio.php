<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Anuncio extends Model
{
    use SoftDeletes, HasFactory;

    protected $table = 'anuncios';

    protected $fillable = [
        'restaurante_id', 'titulo', 'contenido', 'tipo', 'producto_id',
        'precio_promo', 'emoji', 'color', 'activo',
        'mostrar_cliente', 'mostrar_interno',
        'fecha_inicio', 'fecha_fin', 'orden',
    ];

    protected $casts = [
        'activo'          => 'boolean',
        'mostrar_cliente' => 'boolean',
        'mostrar_interno' => 'boolean',
        'fecha_inicio'    => 'datetime:Y-m-d H:i:s',
        'fecha_fin'       => 'datetime:Y-m-d H:i:s',
        'precio_promo'    => 'float',
        'orden'           => 'integer',
    ];

    // Para que Vue reciba automáticamente si está vigente
    protected $appends = ['es_vigente'];

    public function getEsVigenteAttribute()
    {
        $ahora = now();
        return $this->activo && 
               (!$this->fecha_inicio || $this->fecha_inicio <= $ahora) && 
               (!$this->fecha_fin || $this->fecha_fin >= $ahora);
    }

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function producto()
    {
        // Importante: No forzar selects aquí, mejor en el controlador
        return $this->belongsTo(Producto::class);
    }

    public function scopeVigentes($query)
    {
        $ahora = now();
        return $query->where('activo', true)
            ->where(function($q) use ($ahora) {
                $q->whereNull('fecha_inicio')->orWhere('fecha_inicio', '<=', $ahora);
            })
            ->where(function($q) use ($ahora) {
                $q->whereNull('fecha_fin')->orWhere('fecha_fin', '>=', $ahora);
            })
            ->orderBy('orden')
            ->orderBy('created_at', 'desc');
    }
}