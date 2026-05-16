<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenciaPermiso extends Model
{
    protected $table = 'licencia_permisos';

    protected $fillable = [
        'licencia_id',
        'permission_id',
    ];

    public function licencia()
    {
        return $this->belongsTo(Licencia::class);
    }

    public function permission()
    {
        return $this->belongsTo(Permission::class);
    }
}
