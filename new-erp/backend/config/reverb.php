<?php

return [
    'default' => env('REVERB_SERVER', 'reverb'),
    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => (int) env('REVERB_SERVER_PORT', 8083),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => ['tls' => []],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10000),
            'scaling' => ['enabled' => false, 'channel' => 'reverb', 'server' => [
                'url' => env('REDIS_URL'), 'host' => env('REDIS_HOST', '127.0.0.1'), 'port' => env('REDIS_PORT', 6379),
                'username' => env('REDIS_USERNAME'), 'password' => env('REDIS_PASSWORD'), 'database' => env('REDIS_DB', 0), 'timeout' => env('REDIS_TIMEOUT', 60),
            ]],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],
    ],
    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY'), 'secret' => env('REVERB_APP_SECRET'), 'app_id' => env('REVERB_APP_ID'),
            'options' => ['host' => env('REVERB_HOST', '127.0.0.1'), 'port' => env('REVERB_PORT', 8083), 'scheme' => env('REVERB_SCHEME', 'http'), 'useTLS' => false],
            'allowed_origins' => ['*'], 'ping_interval' => 60, 'activity_timeout' => 30, 'max_connections' => null,
            'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
        ]],
    ],
];
