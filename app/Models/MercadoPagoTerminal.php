<?php

namespace App\Models;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Terminal Mercado Pago Point emparejada con una caja del restaurante.
 */
class MercadoPagoTerminal extends Model
{
    use SoftDeletes, BelongsToTenant;

    protected $table = 'mercadopago_terminales';

    protected $fillable = [
        'restaurante_id',
        'terminal_id',
        'alias',
        'store_id',
        'pos_id',
        'device_serial',
        'operating_mode',
        'print_on_terminal',
        'is_active',
        'last_sync_at',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'last_sync_at' => 'datetime',
    ];

    public function restaurante()
    {
        return $this->belongsTo(Restaurante::class);
    }

    /**
     * ¿El terminal permite recibir cobros? (debe estar en modo PDV).
     */
    public function puedeCobrar(): bool
    {
        return $this->is_active && strtoupper($this->operating_mode ?? '') === 'PDV';
    }
}
