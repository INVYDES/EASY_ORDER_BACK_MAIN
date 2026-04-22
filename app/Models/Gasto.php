<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Gasto extends Model
{
    use SoftDeletes;

    protected $table = 'gastos';

    protected $fillable = [
        'restaurante_id','user_id','concepto','categoria','monto','fecha','notas',
    ];

    protected $casts = [
        'monto' => 'float',
        'fecha' => 'date',
    ];

    public function restaurante() { return $this->belongsTo(Restaurante::class); }
    public function user()        { return $this->belongsTo(User::class); }

    // Categorías disponibles
    public static function categorias(): array
    {
        return ['renta','nomina','servicios','insumos','marketing','mantenimiento','general'];
    }
}