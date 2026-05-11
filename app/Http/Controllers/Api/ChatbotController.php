<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiService;
use App\Services\WhatsAppService;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class ChatbotController extends Controller
{
    protected GeminiService $gemini;
    protected WhatsAppService $whatsapp;

    public function __construct(GeminiService $gemini, WhatsAppService $whatsapp)
    {
        $this->gemini = $gemini;
        $this->whatsapp = $whatsapp;
    }

    /**
     * Process a chat message, classify it, create a ticket and respond.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'name' => 'nullable|string|max:100',
            'contact' => 'nullable|string|max:100',
            'restaurante_id' => 'nullable|integer|exists:restaurantes,id'
        ]);

        $userMessage = $request->message;
        $restauranteId = $request->restaurante_id ?: $request->header('X-Restaurante-Id');

        // 1. Analyze Intent with AI
        $analysis = $this->gemini->analyzeIntent($userMessage);

        // 2. Create Ticket in Database
        $ticket = Ticket::create([
            'restaurante_id' => $restauranteId,
            'user_id' => Auth::id(),
            'usuario_nombre' => $request->name ?: (Auth::check() ? Auth::user()->name : 'Invitado'),
            'contacto' => $request->contact ?: (Auth::check() ? Auth::user()->email : 'No proporcionado'),
            'mensaje' => $userMessage,
            'clasificacion' => $analysis['tipo'] ?? 'DUDA_OPERATIVA',
            'prioridad' => $analysis['prioridad'] ?? 'BAJA',
            'respuesta_ia' => $analysis['respuesta_sugerida'] ?? 'Gracias por tu mensaje.',
            'metadata' => $analysis
        ]);

        // 3. Conditional WhatsApp Notification (Only for high priority or critical errors)
        if (($analysis['prioridad'] === 'ALTA' || $analysis['tipo'] === 'ERROR_CRITICO' || $analysis['tipo'] === 'FALLA_SISTEMA') 
            && $analysis['requiere_soporte_humano']) {
            
            $waMessage = "🚨 *INCIDENCIA DETECTADA* (" . ($analysis['prioridad']) . ")\n\n" .
                "🏢 *Restaurante:* " . ($ticket->restaurante?->nombre ?? 'N/A') . "\n" .
                "👤 *Usuario:* " . ($ticket->usuario_nombre) . "\n" .
                "🏷️ *Tipo:* " . ($analysis['tipo']) . "\n" .
                "📝 *Resumen:* " . ($analysis['resumen']) . "\n\n" .
                "💬 *Mensaje:* " . ($userMessage) . "\n\n" .
                "🔗 Ver ticket: " . config('app.url') . "/admin/tickets/" . $ticket->id;

            $this->whatsapp->sendNotification($waMessage);
        }

        return response()->json([
            'success' => true,
            'ticket_id' => $ticket->id,
            'reply' => $analysis['respuesta_sugerida'],
            'category' => $analysis['tipo'],
            'priority' => $analysis['prioridad']
        ]);
    }

    /**
     * Admin: List tickets with filters.
     */
    public function index(Request $request)
    {
        $query = Ticket::query()->with(['user', 'restaurante']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('prioridad')) {
            $query->where('prioridad', $request->prioridad);
        }
        if ($request->filled('clasificacion')) {
            $query->where('clasificacion', $request->clasificacion);
        }

        $tickets = $query->orderByDesc('created_at')->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $tickets
        ]);
    }

    /**
     * Admin: Update ticket status or notes.
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'estado' => 'sometimes|string|in:pendiente,en_proceso,resuelto,descartado',
            'notas_admin' => 'nullable|string',
            'prioridad' => 'sometimes|string|in:ALTA,MEDIA,BAJA'
        ]);

        $ticket = Ticket::findOrFail($id);
        $ticket->update($request->only(['estado', 'notas_admin', 'prioridad']));

        return response()->json([
            'success' => true,
            'message' => 'Ticket actualizado correctamente',
            'data' => $ticket
        ]);
    }
}
