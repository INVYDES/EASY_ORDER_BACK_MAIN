<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RestablecerContrasena extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombreUsuario;
    public string $urlRestablecimiento;

    /**
     * Create a new message instance.
     */
    public function __construct(string $nombreUsuario, string $urlRestablecimiento)
    {
        $this->nombreUsuario = $nombreUsuario;
        $this->urlRestablecimiento = $urlRestablecimiento;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '🔐 Solicitud de restablecimiento de contraseña - Easy Order',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.restablecer-contrasena',
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
