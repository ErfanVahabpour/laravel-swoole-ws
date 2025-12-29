# 🔌⚡ Laravel Swoole WebSocket (laravel-swoole-ws)

A **high-performance WebSocket server library for Laravel** powered by **Swoole / OpenSwoole**, designed for:

* Real-time applications
* IoT / device communication
* Command-based device protocols
* Channel & room broadcasting
* Scalable multi-connection state management (memory / table / Redis)

This package provides a **Laravel-native DX** while running outside the HTTP lifecycle.

> ⚠️ **Status:** Under active development
> APIs are stabilizing, but breaking changes may occur.

## Features

* 🚀 Swoole / OpenSwoole WebSocket server
* 🔌 Laravel service provider & facade
* 🧭 WebSocket routing (`WS::route`)
* 🧠 Command-based device protocol (`WS::command`, `WS::response`)
* 🌐 Handshake URL path scoping (`/pub/chat`, `/attendance`, etc.)
* 🔐 Middleware support (`ws.auth`, custom middleware)
* 👥 Channels & presence authorization
* 📡 Broadcast to rooms / users
* 🗃 Connection stores:
    * In-memory
    * Swoole\Table
    * Redis (multi-server ready)
* 🧪 Testbench + PHPUnit support
* ⚙️ Artisan commands (`ws:start`, `ws:stop`, `ws:reload`)

## Requirements

* PHP **8.2+**
* Laravel **10+**
* **Swoole** or **OpenSwoole** extension enabled

Check extension:

```bash
php -m | grep swoole
php -m | grep openswoole
```

## Installation

```bash
composer require erfanvahabpour/laravel-swoole-ws
```

Laravel auto-discovery is enabled.

## Publish Config & Routes

```bash
php artisan vendor:publish --tag=ws-config
php artisan vendor:publish --tag=ws-routes
php artisan vendor:publish --tag=ws-channels
```

Files created:

* `config/ws.php`
* `routes/ws.php`
* `routes/ws_channels.php`

## Starting the WebSocket Server

```bash
php artisan ws:start
```

Default output:

```
WS server starting on 0.0.0.0:9502
```

Stop / reload:

```bash
php artisan ws:stop
php artisan ws:reload
```

## WebSocket Protocols Supported

This library supports **two protocols at the same time**:

## 1️⃣ Legacy Route Protocol (`WS::route`)

### Client message format

```json
{
  "path": "/chat",
  "action": "send",
  "data": { "text": "hello" },
  "meta": {}
}
```

### Route definition

```php
use EFive\Ws\Facades\WS;

WS::route('/chat', 'send', function ($ctx, $data) {
    return ['ok' => true];
});
```

### Response format

```json
{
  "event": "ws.response",
  "data": { "ok": true },
  "meta": []
}
```

## 2️⃣ Command / Device Protocol (`WS::command`)

Designed for **IoT / terminal / attendance devices**.

### Client → Server

```json
{
  "cmd": "reg",
  "sn": "ABC123",
  "version": "1.0"
}
```

### Server → Client (reply)

```json
{
  "ret": "reg",
  "result": true,
  "cloudtime": "2025-01-01 12:00:00"
}
```

## Handshake Path Scoping (IMPORTANT)

Devices can connect using **different WebSocket URLs**:

```
ws://127.0.0.1:9502/pub/chat
ws://127.0.0.1:9502/attendance
```

The **handshake path becomes a routing scope**.

### Why?

Different devices may use the same `cmd` names (`reg`, `sendlog`, etc.) but require **different logic**.

## Scoped Command Routing

### Define scoped commands

```php
use EFive\Ws\Facades\WS;

WS::scope('/pub/chat')->command('reg', function ($ctx, $payload) {
    return ['pong' => true];
});

WS::scope('/attendance')->command('reg', function ($ctx, $payload) {
    return [
        'device' => 'attendance',
        'sn' => $payload['sn'] ?? null,
    ];
});
```

### Client connects

```bash
wscat -c ws://127.0.0.1:9502/pub/chat
```

Send:

```json
{"cmd":"reg"}
```

Response:

```json
{"ret":"reg","result":true,"pong":true}
```

## Automatic `ret` Replies

When handling `WS::command`:

* If handler **returns an array**, it is automatically sent as:

  ```json
  { "ret": "<cmd>", "result": true, ...payload }
  ```
* If handler **calls `$ctx->replyRet()`**, return `null` to avoid double responses.

### Example

```php
WS::command('reg', fn () => ['pong' => true]);
```

➡ automatically sends:

```json
{"ret":"reg","result":true,"pong":true}
```

## Manual Replies & Server Push

### Manual reply

```php
$ctx->replyRet('reg', true, ['cloudtime' => now()]);
```

### Push command to client

```php
$ctx->pushCmd('getuserlist', ['count' => 10]);
```

## Middleware

### Apply to routes

```php
WS::route('/chat', 'send', $handler)
  ->middleware(['ws.auth']);
```

### Built-in

* `ws.auth` – token-based authentication

## Authentication

### Handshake token

```
ws://host:port/path?token=YOUR_TOKEN
```

### Message token

```json
{
  "cmd": "reg",
  "meta": { "auth": "TOKEN" }
}
```

### Custom resolver (recommended)

```php
config()->set('ws.auth.resolver', function (string $token) {
    return (object) ['id' => 1, 'name' => 'Device'];
});
```

## Channels & Presence

### Define channels

`routes/ws_channels.php`

```php
WS::channel('private-chat.{id}', function ($user, $id) {
    return true;
});
```

### Subscribe

```json
{
  "path": "/ws",
  "action": "subscribe",
  "data": { "channel": "private-chat.1" }
}
```

## Connection Stores

Configured in `config/ws.php`.

### Available drivers

* `memory` – simple, single worker
* `table` – fast shared memory
* `redis` – recommended for multi-server scaling

### Redis example

```php
'store' => [
  'driver' => 'redis',
  'redis' => [
    'connection' => 'default',
    'prefix' => 'ws:',
    'ttl_seconds' => 86400,
  ],
],
```

## Testing

```bash
vendor/bin/phpunit
```

Uses **Orchestra Testbench**.

## CI

GitHub Actions included:

* PHP 8.2 / 8.3
* PHPUnit

## Roadmap (v0.1.0)

* [x] WebSocket routing
* [x] Command protocol
* [x] Handshake path scoping
* [x] Redis connection store
* [x] ws.auth middleware
* [x] Subscribe / unsubscribe routes
* [ ] Presence broadcasting
* [ ] Rate limiting middleware
* [ ] Server metrics endpoint
* [ ] Binary protocol support

## License

MIT © Erfan Vahabpour

