<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\SolicitudContacto;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso interno al equipo cuando entra una solicitud desde el formulario
 * público de contacto. Se envía a config('mail.contact_recipient').
 */
class NuevoContacto extends Mailable
{
    use Queueable, SerializesModels;

    public SolicitudContacto $solicitud;

    public string $urlPanel;

    public function __construct(SolicitudContacto $solicitud)
    {
        $this->solicitud = $solicitud;
        $this->urlPanel = rtrim((string) config('app.frontend_url', 'https://eorder.mx'), '/') . '/panel/contactos';
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $quien = $this->solicitud->negocio ?: $this->solicitud->nombre;

        return new Envelope(
            subject: '📨 Nueva solicitud de contacto: ' . $quien,
            replyTo: [$this->solicitud->email],
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.nuevo-contacto',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
