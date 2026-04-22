<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurante extends Model
{
    use SoftDeletes;
    use LogsActivity;


    protected $table = 'restaurantes';

    protected $fillable = [
        'propietario_id',
        'nombre',
        'calle',
        'ciudad',
        'estado'
    ];

    public function propietario()
    {
        return $this->belongsTo(Propietario::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'restaurante_user');
    }

    public function productos()
    {
        return $this->hasMany(Producto::class);
    }

    public function ordenes()
    {
        return $this->hasMany(Orden::class);
    }
}