<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{use LogsActivity;

    protected $fillable = ['nombre', 'descripcion'];

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'permission_role');
    }
}