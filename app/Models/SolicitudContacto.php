<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud recibida desde el formulario público de contacto.
 *
 * Vive en la base de datos `eorder_contactos` (conexión `contactos`) y usa
 * columnas de fecha en español: creado_en / actualizado_en.
 */
class SolicitudContacto extends Model
{
    protected $connection = 'contactos';

    protected $table = 'solicitudes_contacto';

    public const CREATED_AT = 'creado_en';

    public const UPDATED_AT = 'actualizado_en';

    public const ESTATUS_NUEVO = 'nuevo';

    public const ESTATUS_ASIGNADO = 'asignado';

    public const ESTATUS_CONTACTADO = 'contactado';

    public const ESTATUS_CALIFICADO = 'calificado';

    public const ESTATUS_DESCARTADO = 'descartado';

    public const ESTATUS_CONVERTIDO = 'convertido';

    /** Estatus válidos del flujo de seguimiento. */
    public const ESTATUS = [
        self::ESTATUS_NUEVO,
        self::ESTATUS_ASIGNADO,
        self::ESTATUS_CONTACTADO,
        self::ESTATUS_CALIFICADO,
        self::ESTATUS_DESCARTADO,
        self::ESTATUS_CONVERTIDO,
    ];

    public const TIPOS_CONTACTO = ['distribuidor', 'representante', 'indistinto'];

    public const MEDIOS_PREFERIDOS = ['telefono', 'whatsapp', 'email'];

    public const HORARIOS_PREFERIDOS = ['cualquiera', 'manana', 'tarde'];

    /**
     * `folio` se genera con un trigger en la base (UUID), por eso no es fillable.
     */
    protected $fillable = [
        'nombre',
        'negocio',
        'email',
        'telefono',
        'ciudad',
        'tipo_contacto',
        'medio_preferido',
        'horario_preferido',
        'mensaje',
        'estatus',
        'asignado_a',
        'notas_internas',
        'acepta_privacidad',
        'ip_hash',
        'user_agent',
        'origen',
        'contactado_en',
    ];

    protected $casts = [
        'acepta_privacidad' => 'boolean',
        'creado_en'         => 'datetime',
        'actualizado_en'    => 'datetime',
        'contactado_en'     => 'datetime',
    ];

    /** Filtra por estatus del flujo de seguimiento. */
    public function scopeEstatus(Builder $query, ?string $estatus): Builder
    {
        return $estatus ? $query->where('estatus', $estatus) : $query;
    }

    /** Filtra por tipo de contacto solicitado. */
    public function scopeTipo(Builder $query, ?string $tipo): Builder
    {
        return $tipo ? $query->where('tipo_contacto', $tipo) : $query;
    }

    /** Búsqueda libre por nombre, negocio, correo, teléfono, ciudad o folio. */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        if (!$termino) {
            return $query;
        }

        $termino = trim($termino);

        return $query->where(function (Builder $q) use ($termino) {
            $q->where('nombre', 'like', "%{$termino}%")
              ->orWhere('negocio', 'like', "%{$termino}%")
              ->orWhere('email', 'like', "%{$termino}%")
              ->orWhere('telefono', 'like', "%{$termino}%")
              ->orWhere('ciudad', 'like', "%{$termino}%")
              ->orWhere('folio', 'like', "%{$termino}%");
        });
    }
}
