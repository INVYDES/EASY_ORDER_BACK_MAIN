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
 * Correo de confirmación que se envía al visitante cuando su solicitud
 * de contacto queda registrada correctamente.
 */
class ConfirmacionContacto extends Mailable
{
    use Queueable, SerializesModels;

    public SolicitudContacto $solicitud;

    public function __construct(SolicitudContacto $solicitud)
    {
        $this->solicitud = $solicitud;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✅ Recibimos tu solicitud — Easy Order (Folio: ' . $this->solicitud->folio . ')',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.confirmacion-contacto',
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
