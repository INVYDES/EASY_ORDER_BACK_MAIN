<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
     * Process a chat message with Gemini AI.
     */
    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
            'history' => 'nullable|array'
        ]);

        $userMessage = $request->message;
        $history = $request->history ?? [];

        // Define the system behavior
        $systemPrompt = "Eres el asistente inteligente de TiendaFer (EASY ORDER). " .
            "Tu objetivo es ayudar a los clientes con dudas sobre el menú, horarios y servicios. " .
            "Si el usuario tiene una queja, sugerencia o quiere hablar con una persona real, " .
            "dile que puede dejar su comentario aquí mismo y que llegará directamente al dueño. " .
            "Mantén un tono amable, profesional y servicial. " .
            "Si el mensaje es una sugerencia explícita, agradéceles y pídeles confirmación para enviarla a WhatsApp.";

        $response = $this->gemini->generateResponse($systemPrompt . "\n\nUsuario: " . $userMessage, $history);

        return response()->json([
            'success' => true,
            'reply' => $response
        ]);
    }

    /**
     * Send a comment or suggestion directly to the owner via WhatsApp.
     */
    public function sendSuggestion(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:100',
            'contact' => 'nullable|string|max:100',
            'message' => 'required|string|max:1000'
        ]);

        $name = $request->name ?: 'Usuario Anónimo';
        $contact = $request->contact ?: 'No proporcionado';
        $content = $request->message;

        $whatsappMessage = "📬 *Nueva Sugerencia de TiendaFer*\n\n" .
            "👤 *Nombre:* {$name}\n" .
            "📱 *Contacto:* {$contact}\n\n" .
            "💬 *Mensaje:* {$content}";

        $sent = $this->whatsapp->sendNotification($whatsappMessage);

        if ($sent) {
            return response()->json([
                'success' => true,
                'message' => 'Tu sugerencia ha sido enviada directamente al dueño por WhatsApp. ¡Gracias!'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'No pudimos enviar la sugerencia por WhatsApp en este momento, pero ha sido registrada en el sistema.'
        ], 500);
    }
}
