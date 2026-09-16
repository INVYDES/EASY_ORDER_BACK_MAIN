@extends('emails.base')

@section('title', 'Restablecer contraseña - Easy Order')

@section('content')
    <h2 style="color: #0f172a; margin-top: 0;">Hola, {{ $nombreUsuario }}</h2>
    <p>Has recibido este correo porque se solicitó un restablecimiento de contraseña para tu cuenta en <strong>Easy Order</strong>.</p>
    
    <div style="text-align: center;">
        <a href="{{ $urlRestablecimiento }}" class="btn">Restablecer contraseña</a>
    </div>

    <div style="background-color: #f1f5f9; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #475569; margin-top: 20px;">
        <p style="margin: 0;"><strong>Importante:</strong> Este enlace para restablecer la contraseña expirará en 60 minutos.</p>
        <p style="margin: 5px 0 0 0;">Si no solicitaste este cambio, no se requiere ninguna acción adicional.</p>
        <p style="margin: 5px 0 0 0; word-break: break-all;">Si el botón no funciona, copia y pega el enlace:<br><a href="{{ $urlRestablecimiento }}" style="color: #2563eb;">{{ $urlRestablecimiento }}</a></p>
    </div>
@endsection
