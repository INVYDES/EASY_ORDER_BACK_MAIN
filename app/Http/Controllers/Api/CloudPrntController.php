<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ImpresoraNube;
use App\Models\PrintJob;
use Illuminate\Support\Facades\Log;

class CloudPrntController extends Controller
{
    /**
     * POST /api/cloudprnt/{token}
     * La impresora hace polling para preguntar si hay trabajos o enviar su estado.
     */
    public function poll(Request $request, $token)
    {
        $printer = ImpresoraNube::where('token', $token)->where('is_active', true)->first();

        if (!$printer) {
            return response()->json(['jobReady' => false], 404);
        }

        // Actualizar ultima vez que se comunicó
        $printer->update(['last_polling_at' => now()]);

        // Revisar si hay un trabajo pendiente
        $hasJob = PrintJob::where('impresora_id', $printer->id)
            ->pending()
            ->exists();

        // Enviar respuesta en formato StarPRNT / CloudPRNT
        return response()->json([
            'jobReady' => $hasJob,
            // Lista de formatos que podemos enviarle. 
            // StarPRNT espera text/plain o application/vnd.star.starprnt, etc.
            'mediaTypes' => ['application/vnd.star.starprnt', 'application/vnd.star.line', 'text/plain'],
        ]);
    }

    /**
     * GET /api/cloudprnt/{token}
     * La impresora descarga el trabajo.
     */
    public function download(Request $request, $token)
    {
        $printer = ImpresoraNube::where('token', $token)->where('is_active', true)->first();

        if (!$printer) {
            return response('Not found', 404);
        }

        // Obtener el trabajo más antiguo pendiente
        $job = PrintJob::where('impresora_id', $printer->id)
            ->pending()
            ->orderBy('id', 'asc')
            ->first();

        if (!$job) {
            return response('', 204); // No content
        }

        // Decodificar el payload base64
        $payload = base64_decode($job->payload_base64);

        // El formato depende de cómo lo genere el backend.
        // Si usamos ESC/POS puro (raw), Star puede aceptarlo o preferir StarPRNT.
        // Asumiremos que le mandamos el tipo genérico o el específico de star.
        
        // Devolvemos los bytes crudos
        return response($payload)->header('Content-Type', 'application/vnd.star.starprnt');
    }

    /**
     * DELETE /api/cloudprnt/{token}
     * La impresora notifica que terminó de imprimir el trabajo o falló.
     */
    public function deleteJob(Request $request, $token)
    {
        $printer = ImpresoraNube::where('token', $token)->where('is_active', true)->first();

        if (!$printer) {
            return response()->json(['success' => false], 404);
        }

        // StarPRNT usualmente manda ?code=...
        $code = $request->query('code');
        
        // En CloudPRNT, la petición DELETE significa que el trabajo se completó.
        // Se borra o marca como printed el primer pending.
        // (A veces Star envia un token de job_token, pero para simplificar marcamos el más viejo).
        
        $job = PrintJob::where('impresora_id', $printer->id)
            ->pending()
            ->orderBy('id', 'asc')
            ->first();

        if ($job) {
            if ($code && strpos($code, '2') === 0) {
                 // Códigos 2xx suelen ser ok en star
                 $job->update(['status' => 'printed']);
            } else {
                 // Si manda un code con error o simplemente delete sin code, asumimos printed
                 $job->update(['status' => 'printed']);
            }
        }

        return response()->json(['success' => true]);
    }
}
