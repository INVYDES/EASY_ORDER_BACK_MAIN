<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Licencia extends Model
{
    use SoftDeletes;
    
    protected $fillable = [
        'nombre',
        'tipo',
        'max_restaurantes',
        'max_usuarios',
        'precio',
        'precio_anual',      
        'dias_prueba',       
        'activo',
        'paypal_plan_id',
        'paypal_product_id',
        'mercadopago_plan_id',
        'days_duration'
    ];

    protected $casts = [
        'precio' => 'decimal:2',
        'precio_anual' => 'decimal:2',  
        'dias_prueba' => 'integer',     
        'activo' => 'boolean',
        'max_restaurantes' => 'integer',
        'max_usuarios' => 'integer',
        'days_duration' => 'integer'
    ];

    // Obtener precio formateado
    public function getPrecioFormateadoAttribute()
    {
        if ($this->precio === null || $this->precio == 0) {
            return 'Cotizar';
        }
        return '$' . number_format($this->precio, 2);
    }

    public function getPrecioAnualFormateadoAttribute()
    {
        if ($this->precio_anual === null || $this->precio_anual == 0) {
            return $this->tipo === 'EMPRESA' ? 'Cotizar' : null;
        }
        return '$' . number_format($this->precio_anual, 2);
    }

    // Scopes
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeMensuales($query)
    {
        return $query->where('tipo', 'MENSUAL');
    }

    public function scopeAnuales($query)
    {
        return $query->where('tipo', 'ANUAL');
    }

    public function scopePrueba($query)
    {
        return $query->where('tipo', 'PRUEBA');
    }

    public function scopeEnterprise($query)
    {
        return $query->where('tipo', 'EMPRESA');
    }

    // Relaciones
    public function propietarios()
    {
        return $this->hasMany(PropietarioLicencia::class);
    }
}