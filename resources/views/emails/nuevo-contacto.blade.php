@extends('emails.base')

@section('title', 'Nueva solicitud de contacto - Easy Order')

@section('content')
    <h2 style="color: #0f172a; margin-top: 0;">📨 Nueva solicitud de contacto</h2>
    <p>Entró una solicitud desde el formulario público. Estos son los datos:</p>

    <div style="background-color: #f1f5f9; border-left: 4px solid #2563eb; padding: 16px; margin: 20px 0; border-radius: 4px;">
        <p style="margin: 0; color: #0f172a; font-weight: 700;">{{ $solicitud->nombre }}</p>
        @if ($solicitud->negocio)
            <p style="margin: 4px 0 0 0; color: #475569; font-size: 14px;">{{ $solicitud->negocio }}</p>
        @endif
        <p style="margin: 8px 0 0 0; color: #64748b; font-size: 12px; font-family: monospace;">Folio: {{ $solicitud->folio }}</p>
    </div>

    <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
        <tr>
            <td style="padding: 6px 0; color: #94a3b8; width: 38%;">Correo</td>
            <td style="padding: 6px 0; color: #334155;"><a href="mailto:{{ $solicitud->email }}" style="color: #2563eb;">{{ $solicitud->email }}</a></td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Teléfono</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->telefono }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Ciudad</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->ciudad ?: '—' }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Quiere que lo contacte</td>
            <td style="padding: 6px 0; color: #334155;">{{ ucfirst($solicitud->tipo_contacto) }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Medio y horario preferido</td>
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
            <td style="padding: 6px 0; color: #94a3b8;">Origen</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->origen }}</td>
        </tr>
        <tr>
            <td style="padding: 6px 0; color: #94a3b8;">Recibida</td>
            <td style="padding: 6px 0; color: #334155;">{{ $solicitud->creado_en?->format('d/m/Y H:i') }}</td>
        </tr>
    </table>

    <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; padding: 16px; margin: 20px 0; border-radius: 4px;">
        <p style="margin: 0 0 6px 0; color: #94a3b8; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Mensaje</p>
        <p style="margin: 0; color: #334155; white-space: pre-line;">{{ $solicitud->mensaje }}</p>
    </div>

    <div style="text-align: center;">
        <a href="{{ $urlPanel }}" class="btn">Ver y dar seguimiento en el panel</a>
    </div>

    <p style="font-size: 13px; color: #64748b;">Recuerda asignar un responsable y marcar el estatus para que la solicitud no se quede sin atender.</p>
@endsection
