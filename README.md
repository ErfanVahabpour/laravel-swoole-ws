# Laravel Swoole WebSocket

A **Laravel-first WebSocket server library built on Swoole**, providing clean routing, middleware, channels, and broadcasting concepts similar to Laravel HTTP and Broadcasting.

> ⚠️ **Status: Under Active Development**
> This package is currently in **early development**.
> APIs, configuration, and internal architecture **may change without notice** until a stable release is published.
> **Not recommended for production use yet.**

## Features

* Swoole `WebSocket\Server` integration
* Laravel-style routing via `routes/ws.php`
* Channel authorization via `routes/ws_channels.php`
* Artisan commands to manage the server lifecycle
* Middleware pipeline (auth, throttle, custom)
* Room / channel membership
* Broadcasting helpers (emit, broadcast, presence)
* Clean message protocol (JSON-based)
* Pluggable connection store (memory / Swoole Table)
* Fully container-driven & extensible

## Requirements

* PHP **8.2+**
* Laravel **10+**
* Swoole extension enabled

## Installation

```bash
composer require erfanvahabpour/laravel-swoole-ws
```

Publish configuration and route files:

```bash
php artisan vendor:publish --tag=ws-config
php artisan vendor:publish --tag=ws-routes
php artisan vendor:publish --tag=ws-channels
```

This will create:

```
config/ws.php
routes/ws.php
routes/ws_channels.php
```

## Configuration

`config/ws.php` controls server settings, routing files, storage driver, and middleware.

Example:

```php
return [
    'host' => '0.0.0.0',
    'port' => 9502,

    'routes_file' => base_path('routes/ws.php'),
    'channels_file' => base_path('routes/ws_channels.php'),

    'server' => [
        'worker_num' => 2,
        'daemonize' => 0,
    ],
];
```

## Starting the WebSocket Server

```bash
php artisan ws:start
```

Daemon mode:

```bash
php artisan ws:start --daemon
```

Other commands:

```bash
php artisan ws:stop
php artisan ws:reload
php artisan ws:status
```

## WebSocket Routing

Define WebSocket routes in `routes/ws.php`.

```php
use WS;

WS::route('/chat/private', 'send_msg', [
    \App\Ws\Controllers\SendMsgController::class,
    'index'
])->middleware(['ws.auth']);
```

Incoming message format:

```json
{
  "path": "/chat/private",
  "action": "send_msg",
  "data": {
    "text": "hello"
  },
  "meta": {
    "auth": "token"
  }
}
```

## Controller Example

```php
<?php

namespace App\Ws\Controllers;

use EFive\Ws\Messaging\WsContext;

final class SendMsgController
{
    public function index(WsContext $ctx, array $data): array
    {
        $ctx->join('private-chat.1');

        $ctx->broadcastTo('private-chat.1', 'chat.message', [
            'text' => $data['text'] ?? '',
        ]);

        return ['ok' => true];
    }
}
```

## Channels Authorization

Define channel authorization in `routes/ws_channels.php`.

```php
use WS;

WS::channel('private-chat.{chatId}', function ($user, $chatId) {
    return $user->can('viewChat', (int) $chatId);
});

WS::channel('presence-room.{roomId}', function ($user, $roomId) {
    return [
        'id' => $user->id,
        'name' => $user->name,
    ];
});
```

Supports:

* Private channels
* Presence channels
* Parameterized channel names

## Middleware

WebSocket routes support middleware similar to Laravel HTTP routes.

Examples:

* Authentication (`ws.auth`)
* Throttling (`throttle:20,1`)
* Custom middleware

```php
WS::route('/system', 'ping', fn () => ['pong' => true])
    ->middleware(['throttle:10,1']);
```

## Broadcasting & Rooms

Available from `WsContext`:

* `join($room)`
* `leave($room)`
* `emit($event, $data)`
* `broadcastTo($room, $event, $data)`
* `respond($payload)`

Rooms are stored using:

* Swoole Table (default)
* In-memory store (single worker)

## Development Status

This package is **not stable yet**.

### Known limitations

* API may change
* Redis driver not implemented yet
* No multi-node clustering support
* Limited test coverage

### Planned features

* Redis connection store
* Built-in auth middleware
* Presence events (join/leave)
* Graceful shutdown handling
* Metrics & monitoring hooks
* Typed message validation

## Contributing

Contributions are welcome.

## License

MIT License
