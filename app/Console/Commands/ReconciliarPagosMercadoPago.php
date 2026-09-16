<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Licencia;
use App\Models\PropietarioLicencia;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;
use Carbon\Carbon;

class ReconciliarPagosMercadoPago extends Command
{
    protected $signature = 'app:reconciliar-pagos-mercado-pago {--days=30 : Número de días hacia atrás para buscar}';
    protected $description = 'Busca pagos aprobados en MercadoPago que no se procesaron por el webhook y activa las licencias correspondientes.';

    public function handle()
    {
        $days = (int) $this->option('days');
        $this->info("Iniciando reconciliación de pagos de los últimos {$days} días...");

        if (!env('MERCADOPAGO_ACCESS_TOKEN')) {
            $this->error("No hay token de MercadoPago configurado.");
            return;
        }

        MercadoPagoConfig::setAccessToken(env('MERCADOPAGO_ACCESS_TOKEN'));
        
        $client = new PaymentClient();
        
        try {
            // Fechas en formato ISO 8601
            $beginDate = Carbon::now()->subDays($days)->toIso8601String();
            $endDate = Carbon::now()->toIso8601String();
            
            $this->info("Buscando pagos desde {$beginDate} hasta {$endDate}");
            
            $searchRequest = new \MercadoPago\Net\MPSearchRequest(100, 0, [
                "sort" => "date_created",
                "criteria" => "desc",
                "range" => "date_created",
                "begin_date" => "NOW-{$days}DAYS",
                "end_date" => "NOW",
                "status" => "approved"
            ]);
            
            // Buscar pagos aprobados
            $search = $client->search($searchRequest);
            
            $pagos = $search->results ?? [];
            $this->info("Se encontraron " . count($pagos) . " pagos aprobados en total.");
            
            $procesados = 0;
            
            foreach ($pagos as $payment) {
                $reference = $payment->external_reference ?? '';
                
                // Solo nos interesan los pagos de licencias
                if (!str_starts_with($reference, 'LIC-')) {
                    continue;
                }
                
                $parts = explode('-', $reference);
                if (count($parts) !== 3) {
                    continue;
                }
                
                $licenciaId = (int)$parts[1];
                $propietarioId = (int)$parts[2];
                
                $licencia = Licencia::find($licenciaId);
                if (!$licencia) {
                    continue;
                }
                
                // Buscar si ya existe la licencia activa
                $registro = PropietarioLicencia::where('propietario_id', $propietarioId)
                    ->where('licencia_id', $licenciaId)
                    ->where('estado', 'ACTIVA')
                    ->first();
                
                if ($registro) {
                    // Ya está procesada
                    continue;
                }
                
                $this->info("Encontrado pago aprobado no procesado: {$reference} (Pago ID: {$payment->id})");
                
                // Crear el registro de la licencia
                $dias = $licencia->tipo === 'ANUAL' ? 365 : 30;
                
                $nuevoRegistro = new PropietarioLicencia();
                $nuevoRegistro->propietario_id = $propietarioId;
                $nuevoRegistro->licencia_id = $licenciaId;
                $nuevoRegistro->estado = 'ACTIVA';
                $nuevoRegistro->fecha_inicio = now();
                $nuevoRegistro->fecha_expiracion = now()->addDays($dias);
                $nuevoRegistro->monto_pagado = $payment->transaction_amount;
                $nuevoRegistro->metodo_pago = 'mercadopago';
                $nuevoRegistro->mercadopago_payment_id = $payment->id;
                
                $nuevoRegistro->save();
                
                // Cancelar otras licencias activas
                PropietarioLicencia::where('propietario_id', $propietarioId)
                    ->where('estado', 'ACTIVA')
                    ->where('id', '!=', $nuevoRegistro->id)
                    ->update(['estado' => 'CANCELADA']);
                
                $procesados++;
                $this->info("✅ Licencia activada para Propietario ID: {$propietarioId}");
            }
            
            $this->info("Reconciliación completada. Se procesaron {$procesados} licencias atascadas.");
            
        } catch (\Exception $e) {
            $this->error("Error consultando MercadoPago: " . $e->getMessage());
            Log::error("Error en ReconciliarPagosMercadoPago: " . $e->getMessage());
        }
    }
}
