@extends('emails.base')

@section('title', 'Verifica tu cuenta - Easy Order')

@section('content')
    <h2 style="color: #0f172a; margin-top: 0;">¡Bienvenido a Easy Order, {{ $nombreUsuario }}!</h2>
    <p>Gracias por registrarte. Para comenzar a administrar tu restaurante y acceder a todas las funciones, por favor verifica tu correo electrónico haciendo clic en el siguiente botón:</p>
    
    <div style="text-align: center;">
        <a href="{{ $urlVerificacion }}" class="btn">Verificar mi cuenta</a>
    </div>

    <div style="background-color: #f1f5f9; padding: 12px 16px; border-radius: 6px; font-size: 13px; color: #475569; margin-top: 20px;">
        <p style="margin: 0;"><strong>Nota:</strong> Este enlace expirará en 48 horas.</p>
        <p style="margin: 5px 0 0 0; word-break: break-all;">Si el botón no funciona, copia y pega este enlace en tu navegador:<br><a href="{{ $urlVerificacion }}" style="color: #2563eb;">{{ $urlVerificacion }}</a></p>
    </div>
@endsection
