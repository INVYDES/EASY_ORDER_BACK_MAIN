<?php

namespace App\Mail\Transport;

class GmailApiTransportFactory
{
    public function create(array $config): GmailApiTransport
    {
        return new GmailApiTransport(
            $config['client_id'] ?? '',
            $config['client_secret'] ?? '',
            $config['refresh_token'] ?? ''
        );
    }
}
