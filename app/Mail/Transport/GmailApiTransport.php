<?php

namespace App\Mail\Transport;

use Google\Client as GoogleClient;
use Google\Service\Gmail as GmailService;
use Google\Service\Gmail\Message as GmailMessage;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Exception;

class GmailApiTransport extends AbstractTransport
{
    protected string $clientId;
    protected string $clientSecret;
    protected string $refreshToken;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken)
    {
        parent::__construct();

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
    }

    protected function doSend(SentMessage $message): void
    {
        if (empty($this->clientId) || empty($this->clientSecret) || empty($this->refreshToken)) {
            throw new Exception("Configuración de Gmail API incompleta. Se requieren Client ID, Client Secret y Refresh Token.");
        }

        $client = new GoogleClient();
        $client->setClientId($this->clientId);
        $client->setClientSecret($this->clientSecret);
        $client->refreshToken($this->refreshToken);

        $service = new GmailService($client);

        $rawMessageString = $message->toString();

        // Conversión a Base64Url seguro según especificacion de Gmail API
        $rawUrlSafe = rtrim(strtr(base64_encode($rawMessageString), '+/', '-_'), '=');

        $gmailMessage = new GmailMessage();
        $gmailMessage->setRaw($rawUrlSafe);

        $service->users_messages->send('me', $gmailMessage);
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }
}
