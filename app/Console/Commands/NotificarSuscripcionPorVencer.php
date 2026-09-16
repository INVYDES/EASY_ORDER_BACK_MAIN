<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\PropietarioLicencia;
use App\Mail\SuscripcionPorVencer;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class NotificarSuscripcionPorVencer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notificar-suscripcion-por-vencer';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enviar notificaciones por correo a propietarios con suscripciones por vencer en los próximos 7 días';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Buscando licencias por vencer...');

        $licenciasPorVencer = PropietarioLicencia::with(['propietario', 'licencia'])
            ->porVencer(7)
            ->whereNull('notificado_vencimiento_at')
            ->get();

        if ($licenciasPorVencer->isEmpty()) {
            $this->info('No hay licencias por vencer pendientes de notificar.');
            return Command::SUCCESS;
        }

        $enviados = 0;
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $urlRenovacion = rtrim($frontendUrl, '/') . '/dashboard/planes';

        foreach ($licenciasPorVencer as $licencia) {
            $propietario = $licencia->propietario;
            if (!$propietario) {
                continue;
            }

            $email = $propietario->correo ?? $propietario->users()->first()?->email;

            if (!$email) {
                $this->warn("No se encontró email para el propietario ID: {$propietario->id}");
                continue;
            }

            $nombre = trim(($propietario->nombre ?? '') . ' ' . ($propietario->apellido ?? '')) ?: 'Propietario';
            $planNombre = $licencia->licencia?->nombre ?? 'Plan Activo';
            $fechaExpiracion = $licencia->fecha_expiracion ? $licencia->fecha_expiracion->format('d/m/Y') : 'Próximamente';
            $diasRestantes = $licencia->dias_restantes;

            try {
                Mail::to($email)->send(new SuscripcionPorVencer(
                    $nombre,
                    $planNombre,
                    $fechaExpiracion,
                    $diasRestantes,
                    $urlRenovacion
                ));

                $licencia->update([
                    'notificado_vencimiento_at' => Carbon::now()
                ]);

                $enviados++;
                $this->info("Correo enviado exitosamente a: {$email} (Propietario ID: {$propietario->id})");
                Log::info("Notificación de vencimiento de licencia enviada a {$email} para la licencia ID {$licencia->id}");
            } catch (\Exception $e) {
                $this->error("Error enviando correo a {$email}: " . $e->getMessage());
                Log::error("Error enviando correo de vencimiento de licencia: " . $e->getMessage());
            }
        }

        $this->info("Proceso completado. Se enviaron {$enviados} notificaciones.");
        return Command::SUCCESS;
    }
}
