<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\NuevoContacto;
use App\Models\SolicitudContacto;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ContactoController extends Controller
{
    /** Máximo de filas por página en el listado administrativo. */
    private const MAX_PER_PAGE = 100;

    /**
     * Recibe solicitudes de contacto desde la landing pública y las guarda en
     * `eorder_contactos.solicitudes_contacto` (conexión `contactos`).
     * Ruta pública con throttle para evitar spam.
     */
    public function store(Request $request): JsonResponse
    {
        // Honeypot — si viene relleno, es bot
        if ($request->filled('sitio_web') || $request->filled('website')) {
            return response()->json([
                'success' => true,
                'message' => 'Solicitud recibida.',
            ]);
        }

        $data = $request->validate([
            'nombre'            => ['required', 'string', 'min:3', 'max:120'],
            'negocio'           => ['nullable', 'string', 'max:150'],
            'email'             => ['required', 'email', 'max:190'],
            'telefono'          => ['required', 'string', 'min:10', 'max:25'],
            'ciudad'            => ['nullable', 'string', 'max:120'],
            'tipo_contacto'     => ['required', 'string', Rule::in(SolicitudContacto::TIPOS_CONTACTO)],
            'medio_preferido'   => ['nullable', 'string', Rule::in(SolicitudContacto::MEDIOS_PREFERIDOS)],
            'horario'           => ['nullable', 'string', Rule::in(SolicitudContacto::HORARIOS_PREFERIDOS)],
            'mensaje'           => ['required', 'string', 'min:10', 'max:2000'],
            'origen'            => ['nullable', 'string', 'max:100'],
            'acepta_privacidad' => ['nullable', 'boolean'],
            'aviso_privacidad'  => ['nullable', 'boolean'],
        ]);

        // El consentimiento es obligatorio: sin él no se guarda la solicitud.
        $aceptaPrivacidad = (bool) ($data['acepta_privacidad'] ?? $data['aviso_privacidad'] ?? false);

        if (!$aceptaPrivacidad) {
            throw ValidationException::withMessages([
                'acepta_privacidad' => 'Debes aceptar el aviso de privacidad para enviar tu solicitud.',
            ]);
        }

        try {
            $solicitud = SolicitudContacto::create([
                'nombre'            => $data['nombre'],
                'negocio'           => $data['negocio'] ?? null,
                'email'             => $data['email'],
                'telefono'          => $data['telefono'],
                'ciudad'            => $data['ciudad'] ?? null,
                'tipo_contacto'     => $data['tipo_contacto'],
                'medio_preferido'   => $data['medio_preferido'] ?? 'telefono',
                'horario_preferido' => $data['horario'] ?? 'cualquiera',
                'mensaje'           => $data['mensaje'],
                'estatus'           => SolicitudContacto::ESTATUS_NUEVO,
                'acepta_privacidad' => true,
                'ip_hash'           => $this->hashIp($request),
                'user_agent'        => mb_substr((string) $request->userAgent(), 0, 500),
                'origen'            => $data['origen'] ?? 'contactanos.html',
            ]);

            // El folio (UUID) lo asigna un trigger de la base: lo releemos.
            $solicitud->refresh();
        } catch (Throwable $e) {
            Log::error('Error al guardar solicitud de contacto', [
                'error' => $e->getMessage(),
                'email' => $data['email'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos registrar tu solicitud. Intenta de nuevo en un momento.',
            ], 500);
        }

        // Log para que el equipo pueda verlo rápido en el archivo de logs
        Log::info('Nuevo contacto recibido', [
            'id' => $solicitud->id,
            'folio' => $solicitud->folio,
            'nombre' => $solicitud->nombre,
            'email' => $solicitud->email,
            'telefono' => $solicitud->telefono,
            'tipo' => $solicitud->tipo_contacto,
        ]);

        // Aviso al equipo (correo + WhatsApp). No bloquea la respuesta al visitante.
        $this->avisarEquipo($solicitud);

        return response()->json([
            'success' => true,
            'message' => '¡Gracias! Hemos recibido tu solicitud. Te contactaremos en breve.',
            'data'    => [
                'id'    => $solicitud->id,
                'folio' => $solicitud->folio,
            ],
        ], 201);
    }

    /**
     * Listado administrativo.
     * Filtros: estatus, tipo_contacto, asignado, buscar, per_page.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('per_page', 20);
            $perPage = min(max($perPage, 1), self::MAX_PER_PAGE);

            $solicitudes = SolicitudContacto::query()
                ->estatus($request->get('estatus'))
                ->tipo($request->get('tipo_contacto'))
                ->buscar($request->get('buscar', $request->get('busqueda')))
                ->when($request->filled('asignado_a'), fn ($q) => $q->where('asignado_a', $request->get('asignado_a')))
                ->orderByDesc('creado_en')
                ->paginate($perPage);

            return response()->json([
                'success'    => true,
                'data'       => $solicitudes->items(),
                'pagination' => [
                    'current_page' => $solicitudes->currentPage(),
                    'per_page'     => $solicitudes->perPage(),
                    'total'        => $solicitudes->total(),
                    'last_page'    => $solicitudes->lastPage(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Error al listar solicitudes de contacto', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener las solicitudes de contacto',
            ], 500);
        }
    }

    /**
     * Conteos por estatus para las tarjetas del panel.
     */
    public function resumen(): JsonResponse
    {
        try {
            $conteos = SolicitudContacto::query()
                ->selectRaw('estatus, COUNT(*) as total')
                ->groupBy('estatus')
                ->pluck('total', 'estatus');

            $porEstatus = [];
            foreach (SolicitudContacto::ESTATUS as $estatus) {
                $porEstatus[$estatus] = (int) ($conteos[$estatus] ?? 0);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'total'     => (int) $conteos->sum(),
                    'por_estatus' => $porEstatus,
                    'ultimos_7_dias' => SolicitudContacto::query()
                        ->where('creado_en', '>=', now()->subDays(7))
                        ->count(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Error al obtener el resumen de contactos', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener el resumen de solicitudes',
            ], 500);
        }
    }

    /**
     * Detalle de una solicitud.
     */
    public function show(int $id): JsonResponse
    {
        $solicitud = SolicitudContacto::find($id);

        if (!$solicitud) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud de contacto no existe',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $solicitud,
        ]);
    }

    /**
     * Seguimiento de la solicitud: estatus, responsable y notas internas.
     * Al pasar a `contactado` se marca la fecha de contacto automáticamente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $solicitud = SolicitudContacto::find($id);

        if (!$solicitud) {
            return response()->json([
                'success' => false,
                'message' => 'La solicitud de contacto no existe',
            ], 404);
        }

        $data = $request->validate([
            'estatus'        => ['sometimes', 'string', Rule::in(SolicitudContacto::ESTATUS)],
            'asignado_a'     => ['sometimes', 'nullable', 'string', 'max:120'],
            'notas_internas' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        if (empty($data)) {
            return response()->json([
                'success' => false,
                'message' => 'No hay cambios por aplicar',
            ], 422);
        }

        try {
            if (array_key_exists('estatus', $data)) {
                $solicitud->estatus = $data['estatus'];

                if ($data['estatus'] === SolicitudContacto::ESTATUS_CONTACTADO && !$solicitud->contactado_en) {
                    $solicitud->contactado_en = now();
                }
            }

            if (array_key_exists('asignado_a', $data)) {
                $solicitud->asignado_a = $data['asignado_a'] ?: null;

                // Si se asigna a alguien y aún no se ha trabajado, pasa a `asignado`.
                if ($solicitud->asignado_a
                    && !array_key_exists('estatus', $data)
                    && $solicitud->estatus === SolicitudContacto::ESTATUS_NUEVO) {
                    $solicitud->estatus = SolicitudContacto::ESTATUS_ASIGNADO;
                }
            }

            if (array_key_exists('notas_internas', $data)) {
                $solicitud->notas_internas = $data['notas_internas'];
            }

            $solicitud->save();
            $solicitud->refresh();
        } catch (Throwable $e) {
            Log::error('Error al actualizar solicitud de contacto', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo actualizar la solicitud',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Solicitud actualizada',
            'data'    => $solicitud,
        ]);
    }

    /**
     * Avisa al equipo por correo y por WhatsApp que entró una solicitud.
     * Cualquier fallo se registra en el log pero nunca afecta la respuesta al
     * visitante: la solicitud ya quedó guardada.
     */
    private function avisarEquipo(SolicitudContacto $solicitud): void
    {
        foreach ($this->destinatariosAviso() as $destinatario) {
            try {
                Mail::to($destinatario)->send(new NuevoContacto($solicitud));
            } catch (Throwable $e) {
                Log::warning('No se pudo enviar el correo de nueva solicitud de contacto', [
                    'destinatario' => $destinatario,
                    'folio' => $solicitud->folio,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        try {
            app(WhatsAppService::class)->sendNotification($this->mensajeWhatsApp($solicitud));
        } catch (Throwable $e) {
            Log::warning('No se pudo enviar el aviso de WhatsApp de la solicitud', [
                'folio' => $solicitud->folio,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Buzones que reciben el aviso (config/mail.php → MAIL_CONTACT_RECIPIENT).
     *
     * @return array<int, string>
     */
    private function destinatariosAviso(): array
    {
        return collect(explode(',', (string) config('mail.contact_recipient')))
            ->map(fn ($correo) => trim((string) $correo))
            ->filter(fn ($correo) => filter_var($correo, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resumen de la solicitud para el aviso de WhatsApp.
     */
    private function mensajeWhatsApp(SolicitudContacto $solicitud): string
    {
        $lineas = [
            '📨 Nueva solicitud de contacto',
            'Folio: ' . $solicitud->folio,
            $solicitud->nombre . ($solicitud->negocio ? ' — ' . $solicitud->negocio : ''),
            '📞 ' . $solicitud->telefono . ' · ✉️ ' . $solicitud->email,
        ];

        if ($solicitud->ciudad) {
            $lineas[] = '📍 ' . $solicitud->ciudad;
        }

        $lineas[] = 'Contacto: ' . $solicitud->tipo_contacto . ' (' . $solicitud->medio_preferido . ', ' . $solicitud->horario_preferido . ')';
        $lineas[] = 'Mensaje: ' . mb_strimwidth(trim((string) $solicitud->mensaje), 0, 240, '…');
        $lineas[] = rtrim((string) config('app.frontend_url', 'https://eorder.mx'), '/') . '/panel/contactos';

        return implode("\n", $lineas);
    }

    /**
     * Hash del IP para no guardar la dirección en claro (columna ip_hash, CHAR(64)).
     */
    private function hashIp(Request $request): string
    {
        return hash('sha256', (string) $request->ip() . '|' . config('app.key'));
    }
}
