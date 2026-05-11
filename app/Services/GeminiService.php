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
}
