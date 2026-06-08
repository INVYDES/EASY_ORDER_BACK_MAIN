<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        // Capacitor / Android (APK)
        'http://localhost',
        'capacitor://localhost',
        'ionic://localhost',
        'http://localhost:5173',
        'http://localhost:3000',
        'http://127.0.0.1:5173',
        'http://localhost:8080',
        'http://localhost:8100',
        // Android Emulator
        'http://10.0.2.2',
        'http://10.0.2.2:5173',
        'http://10.0.2.2:8080',
        'http://10.0.2.2:8100',
        // Producci�n - con y sin www
        'https://corion.mx',
        'https://www.corion.mx',
        'http://corion.mx',
        'http://www.corion.mx',
    ],

    'allowed_origins_patterns' => [
        '#^https?://(www\.)?corion\.mx#',
        '#^(capacitor|ionic)://localhost#',
        '#^http://localhost(:\d+)?$#',
        '#^http://10\.0\.2\.2(:\d+)?$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];