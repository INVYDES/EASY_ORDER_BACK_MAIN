<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuscripcionPorVencer extends Mailable
{
    use Queueable, SerializesModels;

    public string $nombrePropietario;
    public string $planNombre;
    public string $fechaExpiracion;
    public int $diasRestantes;
    public string $urlRenovacion;

    /**
     * Create a new message instance.
     */
    public function __construct(
        string $nombrePropietario,
        string $planNombre,
        string $fechaExpiracion,
        int $diasRestantes,
        string $urlRenovacion
    ) {
        $this->nombrePropietario = $nombrePropietario;
        $this->planNombre = $planNombre;
        $this->fechaExpiracion = $fechaExpiracion;
        $this->diasRestantes = $diasRestantes;
        $this->urlRenovacion = $urlRenovacion;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '⚠️ Tu suscripción a Easy Order está por vencer',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.suscripcion-por-vencer',
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
