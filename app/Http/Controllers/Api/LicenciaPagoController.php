<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PropietarioLicencia;
use App\Models\Licencia;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LicenciaPagoController extends Controller
{
    /**
     * Capturar pago de PayPal (callback después del pago exitoso)
     */
    public function capturar(Request $request)
    {
        try {
            $orderId = $request->query('token');
            
            if (!$orderId) {
                return redirect()->to(env('FRONTEND_URL') . '/licencias/error?motivo=sin_token');
            }

            // Buscar la suscripción pendiente
            $propietarioLicencia = PropietarioLicencia::where('paypal_subscription_id', $orderId)
                ->where('estado', 'PENDIENTE')
                ->first();

            if ($propietarioLicencia) {
                $licencia = $propietarioLicencia->licencia;
                $dias = $licencia->tipo === 'MENSUAL' ? 30 : 365;

                $propietarioLicencia->update([
                    'estado' => 'ACTIVA',
                    'fecha_inicio' => Carbon::now(),
                    'fecha_expiracion' => Carbon::now()->addDays($dias)
                ]);

                Log::info('Licencia activada por PayPal', [
                    'propietario_id' => $propietarioLicencia->propietario_id,
                    'licencia_id' => $licencia->id,
                    'subscription_id' => $orderId
                ]);
            } else {
                Log::warning('No se encontró suscripción pendiente', ['subscription_id' => $orderId]);
            }

            return redirect()->to(env('FRONTEND_URL') . '/licencias/exito');

        } catch (\Exception $e) {
            Log::error('Error capturar pago PayPal: ' . $e->getMessage());
            return redirect()->to(env('FRONTEND_URL') . '/licencias/error?motivo=' . urlencode($e->getMessage()));
        }
    }

    /**
     * Cancelar pago de PayPal
     */
    public function cancelar(Request $request)
    {
        Log::info('Pago de licencia cancelado por usuario', [
            'token' => $request->query('token')
        ]);
        
        return redirect()->to(env('FRONTEND_URL') . '/licencias/cancelado');
    }

    /**
     * Obtener mi licencia activa
     */
    public function miLicencia(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            $propietario = $user->propietario;

            if (!$propietario) {
                return response()->json([
                    'success' => false,
                    'message' => 'Propietario no encontrado'
                ], 404);
            }

            $licenciaActiva = PropietarioLicencia::with('licencia')
                ->where('propietario_id', $propietario->id)
                ->where('estado', 'ACTIVA')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$licenciaActiva) {
                return response()->json([
                    'success' => true,
                    'data' => null,
                    'message' => 'No tienes una licencia activa'
                ]);
            }

            $diasRestantes = 0;
            if ($licenciaActiva->fecha_expiracion) {
                $diasRestantes = max(0, Carbon::now()->diffInDays($licenciaActiva->fecha_expiracion, false));
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $licenciaActiva->id,
                    'licencia' => $licenciaActiva->licencia,
                    'estado' => $licenciaActiva->estado,
                    'fecha_inicio' => $licenciaActiva->fecha_inicio,
                    'fecha_expiracion' => $licenciaActiva->fecha_expiracion,
                    'dias_restantes' => $diasRestantes,
                    'metodo_pago' => $licenciaActiva->metodo_pago ?? 'paypal'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error al obtener mi licencia: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tu licencia: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar estado de una suscripción (callback para frontend)
     */
    public function verificarSuscripcion(Request $request, $subscriptionId)
    {
        try {
            $propietarioLicencia = PropietarioLicencia::where('paypal_subscription_id', $subscriptionId)->first();

            if (!$propietarioLicencia) {
                return response()->json([
                    'success' => false,
                    'message' => 'Suscripción no encontrada'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'estado' => $propietarioLicencia->estado,
                    'fecha_inicio' => $propietarioLicencia->fecha_inicio,
                    'fecha_expiracion' => $propietarioLicencia->fecha_expiracion,
                    'dias_restantes' => $propietarioLicencia->fecha_expiracion 
                        ? max(0, Carbon::now()->diffInDays($propietarioLicencia->fecha_expiracion, false))
                        : 0
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error al verificar suscripción'
            ], 500);
        }
    }
}