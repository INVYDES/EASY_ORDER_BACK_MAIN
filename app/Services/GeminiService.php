<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/';
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.key');
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');
    }

    /**
     * Generate a response from Gemini.
     */
    public function generateResponse(string $prompt, array $history = []): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning('Gemini API Key is not configured.');
            return "Lo siento, mi servicio de inteligencia no está configurado correctamente.";
        }

        try {
            $contents = [];
            
            // Add history if needed
            foreach ($history as $msg) {
                $contents[] = [
                    'role' => $msg['role'] === 'user' ? 'user' : 'model',
                    'parts' => [['text' => $msg['content']]]
                ];
            }

            $contents[] = [
                'role' => 'user',
                'parts' => [['text' => $prompt]]
            ];

            $response = Http::post($this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey, [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => 0.7,
                    'topK' => 40,
                    'topP' => 0.95,
                    'maxOutputTokens' => 1024,
                ]
            ]);

            if ($response->successful()) {
                return $response->json('candidates.0.content.parts.0.text');
            }

            Log::error('Gemini API Error: ' . $response->body());
            return "Tuve un pequeño problema procesando tu mensaje. ¿Puedes intentar de nuevo?";

        } catch (\Exception $e) {
            Log::error('Gemini Service Exception: ' . $e->getMessage());
            return "Lo siento, ocurrió un error inesperado al intentar responderte.";
        }
    }

    /**
     * Analyze user intent and categorize the message.
     * Returns a structured array.
     */
    public function analyzeIntent(string $message): array
    {
        $prompt = "Analiza el siguiente mensaje de un usuario de un sistema de gestión de restaurantes (TiendaFer/EASY ORDER). " .
            "Determina su categoría, prioridad y si requiere atención humana inmediata.\n\n" .
            "Categorías permitidas: ERROR_CRITICO, FALLA_SISTEMA, SUGERENCIA_MEJORA, DUDA_OPERATIVA, SPAM, FUERA_DE_CONTEXTO, CLIENTE_MOLESTO, POSIBLE_FRAUDE.\n" .
            "Prioridades: ALTA, MEDIA, BAJA.\n\n" .
            "Responde ÚNICAMENTE con un objeto JSON válido con este formato:\n" .
            "{\n" .
            "  \"tipo\": \"CATEGORIA\",\n" .
            "  \"prioridad\": \"PRIORIDAD\",\n" .
            "  \"resumen\": \"Resumen breve en 5-10 palabras\",\n" .
            "  \"requiere_soporte_humano\": true/false,\n" .
            "  \"respuesta_sugerida\": \"Respuesta amable para el usuario\"\n" .
            "}\n\n" .
            "Mensaje del usuario: \"$message\"";

        try {
            $response = Http::post($this->baseUrl . $this->model . ':generateContent?key=' . $this->apiKey, [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.1, // Low temperature for consistent JSON
                    'responseMimeType' => 'application/json'
                ]
            ]);

            if ($response->successful()) {
                $rawText = $response->json('candidates.0.content.parts.0.text');
                // Clean markdown code blocks if Gemini includes them despite the prompt
                $cleanJson = preg_replace('/^```json\s*|\s*```$/', '', trim($rawText));
                $data = json_decode($cleanJson, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    return $data;
                }
            }

            Log::error('Gemini Intent Analysis Failed: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('Gemini Intent Analysis Exception: ' . $e->getMessage());
        }

        // Default fallback if analysis fails
        return [
            'tipo' => 'DUDA_OPERATIVA',
            'prioridad' => 'BAJA',
            'resumen' => 'No se pudo clasificar el mensaje',
            'requiere_soporte_humano' => true,
            'respuesta_sugerida' => 'Gracias por tu mensaje. Un agente revisará tu solicitud pronto.'
        ];
    }
}
