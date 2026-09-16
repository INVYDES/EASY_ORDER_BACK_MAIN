@extends('emails.base')

@section('title', 'Suscripción por vencer - Easy Order')

@section('content')
    <h2 style="color: #0f172a; margin-top: 0;">¡Hola, {{ $nombrePropietario }}!</h2>
    <p>Te recordamos que tu licencia del plan <strong>{{ $planNombre }}</strong> en <strong>Easy Order</strong> está por vencer.</p>
    
    <div style="background-color: #fffbebf4; border-left: 4px solid #f59e0b; padding: 16px; margin: 20px 0; border-radius: 4px;">
        <p style="margin: 0; color: #92400e; font-weight: 600;">⚠️ Tiempo restante: {{ $diasRestantes }} {{ $diasRestantes == 1 ? 'día' : 'días' }}</p>
        <p style="margin: 5px 0 0 0; color: #b45309; font-size: 14px;">Fecha de vencimiento: {{ $fechaExpiracion }}</p>
    </div>

    <p>Para evitar interrupciones en el servicio de tu restaurante y seguir disfrutando de todas las herramientas del sistema, renueva tu membresía a tiempo.</p>

    <div style="text-align: center;">
        <a href="{{ $urlRenovacion }}" class="btn">Renovar mi suscripción ahora</a>
    </div>

    <p style="font-size: 13px; color: #64748b;">Si tienes alguna duda o necesitas ayuda con tu pago, contáctanos a soporte@eorder.mx.</p>
@endsection
