<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $provider;
    protected string $token;
    protected string $phoneId;
    protected string $recipient;

    protected string $evolutionUrl;
    protected string $evolutionKey;
    protected string $evolutionInstance;

    public function __construct()
    {
        $this->provider = (string) config('services.whatsapp.provider', 'evolution');
        $this->token = (string) config('services.whatsapp.access_token', '');
        $this->phoneId = (string) config('services.whatsapp.phone_number_id', '');
        $this->recipient = (string) config('services.whatsapp.recipient_number', '522294848144');

        $this->evolutionUrl = rtrim((string) config('services.whatsapp.evolution_url', ''), '/');
        $this->evolutionKey = (string) config('services.whatsapp.evolution_key', '');
        $this->evolutionInstance = (string) config('services.whatsapp.evolution_instance', 'eorder');
    }

    /**
     * Send a notification message to the administrator/owner or specified recipient.
     */
    public function sendNotification(string $message, ?string $toRecipient = null): bool
    {
        $targetRecipient = $toRecipient ?: $this->recipient;

        if ($this->provider === 'evolution' || !empty($this->evolutionUrl)) {
            return $this->sendViaEvolution($message, $targetRecipient);
        }

        return $this->sendViaMeta($message, $targetRecipient);
    }

    /**
     * Send text message via Evolution API
     */
    protected function sendViaEvolution(string $message, string $recipient): bool
    {
        if (empty($this->evolutionUrl) || empty($this->evolutionKey)) {
            Log::info('Evolution WhatsApp API not fully configured. Message logged instead: ' . $message);
            return false;
        }

        try {
            $endpoint = "{$this->evolutionUrl}/message/sendText/{$this->evolutionInstance}";

            $response = Http::withHeaders([
                'apikey' => $this->evolutionKey,
                'Content-Type' => 'application/json',
            ])->post($endpoint, [
                'number' => $recipient,
                'text' => $message,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Evolution API Error: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Evolution Service Exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Send text message via Meta Cloud API
     */
    protected function sendViaMeta(string $message, string $recipient): bool
    {
        if (empty($this->token) || empty($this->phoneId)) {
            Log::info('Meta WhatsApp API not fully configured. Message logged instead: ' . $message);
            return false;
        }

        try {
            $response = Http::withToken($this->token)
                ->post("https://graph.facebook.com/v17.0/{$this->phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $recipient,
                    'type' => 'text',
                    'text' => ['body' => $message]
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Meta WhatsApp API Error: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('Meta WhatsApp Service Exception: ' . $e->getMessage());
            return false;
        }
    }
}
