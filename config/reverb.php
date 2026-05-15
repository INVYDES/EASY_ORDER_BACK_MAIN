<?php

return [

    'apps' => [

        [
            'app_id' => env('REVERB_APP_ID'),
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'allowed_origins' => ['*'],
            'ping_interval' => env('REVERB_PING_INTERVAL', 60),
            'max_message_size' => env('REVERB_MAX_MESSAGE_SIZE', 65535),
        ],

    ],

    'scaling' => [
        'enabled' => env('REVERB_SCALING_ENABLED', false),
        'channel' => env('REVERB_SCALING_CHANNEL', 'reverb_scaling'),
        'server' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'port' => env('REDIS_PORT', '6379'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'database' => env('REDIS_DB', '0'),
        ],
    ],

    'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),

    'tls' => [
        'enabled' => env('REVERB_TLS_ENABLED', false),
        'cert' => env('REVERB_TLS_CERT'),
        'key' => env('REVERB_TLS_KEY'),
        'passphrase' => env('REVERB_TLS_PASSPHRASE'),
    ],

];
