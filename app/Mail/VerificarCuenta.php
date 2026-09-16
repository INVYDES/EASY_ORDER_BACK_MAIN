<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VerificarCuenta extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombreUsuario;
    public string $urlVerificacion;

    /**
     * Create a new message instance.
     */
    public function __construct(string $nombreUsuario, string $urlVerificacion)
    {
        $this->nombreUsuario = $nombreUsuario;
        $this->urlVerificacion = $urlVerificacion;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '✉️ Confirma tu correo electrónico - Easy Order',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.verificar-cuenta',
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
