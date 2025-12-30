<?php

return [
    'host' => env('WS_HOST', '0.0.0.0'),
    'port' => (int)env('WS_PORT', 9502),

    'pid_file' => storage_path('ws/ws.pid'),

    'routes_file' => base_path('routes/ws.php'),
    'channels_file' => base_path('routes/ws_channels.php'),

    'server' => [
        'worker_num' => (int)env('WS_WORKER_NUM', 2),
        'task_worker_num' => (int)env('WS_TASK_WORKER_NUM', 0),
        'max_request' => (int)env('WS_MAX_REQUEST', 2000),
        'dispatch_mode' => 2,
        'daemonize' => (int)env('WS_DAEMONIZE', 0),
        'log_file' => storage_path('logs/swoole-ws.log'),
        'open_websocket_ping_frame' => true,
        'websocket_ping_interval' => (int)env('WS_PING_INTERVAL', 20),
        'websocket_ping_timeout' => (int)env('WS_PING_TIMEOUT', 60),
    ],

    'auth' => [
        'guard' => env('WS_AUTH_GUARD', 'api'),
        'token_input_key' => 'auth', // meta.auth
        'handshake_query_key' => env('WS_HANDSHAKE_TOKEN_KEY', 'token'),

        // If you want custom auth resolution, set this to a callable in a service provider:
        // 'resolver' => fn (string $token): ?object => ...
        'resolver' => null,
    ],

    'middleware_aliases' => [
        'ws.auth' => \EFive\Ws\Middleware\WsAuthenticate::class,
    ],

    'middleware' => [
        // global middleware applied to every WS route
        // \App\Ws\Middleware\Authenticate::class,
    ],

    'store' => [
        'driver' => env('WS_STORE', 'table'), // memory|table|redis
        'table' => [
            'size' => 4096,
        ],
        'redis' => [
            'connection' => env('WS_REDIS_CONNECTION', 'default'),
            'prefix' => env('WS_REDIS_PREFIX', 'ws:'),
            'ttl_seconds' => (int) env('WS_REDIS_TTL', 86400),
        ],
    ],

    'builtin_routes' => [
        'enabled' => true,
        'path' => '/ws',
    ],

    'bus' => [
        'enabled' => env('WS_BUS_ENABLED', true),
        'connection' => env('WS_BUS_REDIS', 'default'),
        'channel' => env('WS_BUS_CHANNEL', 'ws:push'),
    ],
];
