<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Paquete extends Model
{
    use HasFactory;

    protected $table = 'paquetes';

    protected $fillable = [
        'restaurante_id',
        'propietario_id',
        'nombre',
        'descripcion',
        'precio',
        'imagen',
        'activo'
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'activo' => 'boolean',
    ];

    /**
     * RELACIONES
     */
    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function productos()
    {
        return $this->belongsToMany(Producto::class, 'paquete_producto')
                    ->withPivot('cantidad')
                    ->withTimestamps();
    }

    /**
     * ACCESORS PARA IMAGEN (Siguiendo el patrón de Producto)
     */
    public function getImagenUrlAttribute()
    {
        if ($this->imagen) {
            if (filter_var($this->imagen, FILTER_VALIDATE_URL)) {
                return $this->imagen;
            }
            return asset('storage/' . $this->imagen);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->nombre) . '&color=7F9CF5&background=EBF4FF';
    }

    protected $appends = ['imagen_url'];
}
