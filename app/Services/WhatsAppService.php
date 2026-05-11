<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $token;
    protected string $phoneId;
    protected string $recipient;

    public function __construct()
    {
        $this->token = config('services.whatsapp.access_token', '');
        $this->phoneId = config('services.whatsapp.phone_number_id', '');
        $this->recipient = config('services.whatsapp.recipient_number', '');
    }

    /**
     * Send a notification message to the administrator/owner.
     */
    public function sendNotification(string $message): bool
    {
        if (empty($this->token) || empty($this->phoneId) || empty($this->recipient)) {
            Log::info('WhatsApp API not fully configured. Message logged instead: ' . $message);
            return false;
        }

        try {
            $response = Http::withToken($this->token)
                ->post("https://graph.facebook.com/v17.0/{$this->phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $this->recipient,
                    'type' => 'text',
                    'text' => ['body' => $message]
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('WhatsApp API Error: ' . $response->body());
            return false;

        } catch (\Exception $e) {
            Log::error('WhatsApp Service Exception: ' . $e->getMessage());
            return false;
        }
    }
}
