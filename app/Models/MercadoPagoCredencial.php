<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Credenciales OAuth de Mercado Pago por restaurante.
 *
 * Cada restaurante conecta SU PROPIA cuenta de Mercado Pago (integración de
 * terceros / marketplace), por lo que el dinero de las ventas cae directo en
 * su cuenta y no en la de la plataforma.
 */
class MercadoPagoCredencial extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'mercadopago_credenciales';

    protected $fillable = [
        'restaurante_id',
        'mp_user_id',
        'access_token',
        'refresh_token',
        'public_key',
        'live_mode',
        'scope',
        'token_expires_at',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'live_mode'        => 'boolean',
        'token_expires_at' => 'datetime',
        'connected_at'     => 'datetime',
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    public function terminales()
    {
        return $this->hasMany(MercadoPagoTerminal::class, 'restaurante_id', 'restaurante_id');
    }

    /**
     * ¿La conexión sirve para operar? (tiene token y, si aplica, refresh).
     */
    public function estaVigente(): bool
    {
        if (!$this->access_token) {
            return false;
        }

        if ($this->token_expires_at && $this->token_expires_at->isPast()) {
            return (bool) $this->refresh_token;
        }

        return true;
    }
}
