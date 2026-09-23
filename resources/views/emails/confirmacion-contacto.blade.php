@extends('emails.base')

@section('title', 'Solicitud recibida - Easy Order')

@section('content')
    <h2 style="color: #0f172a; margin-top: 0;">✅ ¡Hemos recibido tu solicitud!</h2>

    <p>Hola <strong>{{ $solicitud->nombre }}</strong>,</p>

    <p>Gracias por ponerte en contacto con <strong>Easy Order</strong>. Tu solicitud quedó registrada con los siguientes datos:</p>

    <div style="background-color: #f1f5f9; border-left: 4px solid #2563eb; padding: 16px; margin: 20px 0; border-radius: 4px;">
        <p style="margin: 0; color: #64748b; font-size: 12px; font-family: monospace;">Folio: {{ $solicitud->folio }}</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 6px 0; color: #94a3b8; width: 38%;">Nombre</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->nombre }}</td>
        </tr>
        @if ($solicitud->negocio)
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Negocio</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->negocio }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Correo</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->email }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Teléfono</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->telefono }}</td>
        </tr>
        @if ($solicitud->ciudad)
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Ciudad</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->ciudad }}</td>
        </tr>
        @endif
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Medio preferido</td>
            <td style="padding: 6px 0; color: #334155;">
                {{ ucfirst($solicitud->medio_preferido) }}
                @if ($solicitud->horario_preferido === 'manana')
                    (9:00 a 13:00)
                @elseif ($solicitud->horario_preferido === 'tarde')
                    (13:00 a 18:00)
                @else
                    (cualquier horario)
                @endif
            </td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Registrada</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->creado_en?->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <div style="background-color: #f0fdf4; border: 1px solid #bbf7d0; padding: 16px; margin: 24px 0; border-radius: 8px;">
        <p style="margin: 0; color: #166534; font-size: 14px;">
            <strong>¿Qué sigue?</strong><br>
            Un asesor de Easy Order se pondrá en contacto contigo por
            <strong>{{ $solicitud->medio_preferido === 'whatsapp' ? 'WhatsApp' : ($solicitud->medio_preferido === 'email' ? 'correo electrónico' : 'teléfono') }}</strong>
            en las próximas horas hábiles.
        </p>
    </div>

    <p style="font-size: 13px; color: #64748b;">
        Si tienes alguna duda antes de que te contactemos, puedes responder directamente a este correo o escribirnos por WhatsApp.
    </p>
@endsection
