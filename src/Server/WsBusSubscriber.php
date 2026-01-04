<?php

namespace EFive\Ws\Server;

use EFive\Ws\Contracts\ConnectionStore;
use EFive\Ws\Messaging\Protocol;
use Swoole\WebSocket\Server;

final class WsBusSubscriber
{
    public function __construct(
        private Server $server,
        private ConnectionStore $store,
    ) {}

    public function start(): void
    {
        $prefix  = (string) config('database.redis.options.prefix', '');
        $channel = $prefix . (string) config('ws.bus.channel', 'ws:push');

        if (!function_exists('go')) {
            return;
        }

        // Best-effort: enable coroutine TCP hook (recommended by OpenSwoole docs for phpredis/predis)
        // Prefer doing this ONCE in your server bootstrap, but this won't hurt if called per worker.
        $this->enableTcpHookBestEffort();

        go(function () use ($channel) {
            $sleepSeconds = 1;

            while (true) {
                try {
                    if (!class_exists(\Redis::class)) {
                        logger()->error('WS Redis subscriber error', [
                            'message' => 'PhpRedis extension (Redis class) not found. Install/enable ext-redis.',
                        ]);
                        echo 'PhpRedis extension (Redis class) not found. Install/enable ext-redis.';
                        return;
                    }

                    $client = new \Redis();

                    $cfg  = config('database.redis.' . config('ws.bus.connection', 'default'), []);
                    $host = $cfg['host'] ?? '127.0.0.1';
                    $port = (int) ($cfg['port'] ?? 6379);
                    $auth = $cfg['password'] ?? null;
                    $db   = (int) ($cfg['database'] ?? 0);

                    // connect (timeout in seconds)
                    if (!$client->connect($host, $port, 2.0)) {
                        \Swoole\Coroutine::sleep($sleepSeconds);
                        continue;
                    }

                    if (!empty($auth)) {
                        $client->auth($auth);
                    }

                    if ($db > 0) {
                        $client->select($db);
                    }

                    // Subscribe connections should not have a read timeout
                    $client->setOption(\Redis::OPT_READ_TIMEOUT, -1);

                    // Subscribe blocks, but we're inside a coroutine so it's OK (with HOOK_TCP enabled)
                    $client->subscribe([$channel], function (\Redis $redis, string $chan, string $payload) {
                        $this->handleMessage($payload);
                    });

                    // If subscribe ever returns, treat as disconnect and reconnect.
                    try {
                        $client->close();
                    } catch (\Throwable) {
                        // ignore
                    }
                } catch (\Throwable $e) {
                    logger()->error('WS Redis subscriber error', [
                        'message' => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ]);
                }

                \Swoole\Coroutine::sleep($sleepSeconds);
            }
        });
    }

    private function enableTcpHookBestEffort(): void
    {
        try {
            // OpenSwoole preferred
            if (class_exists(\OpenSwoole\Coroutine::class) && class_exists(\OpenSwoole\Runtime::class)) {
                \OpenSwoole\Coroutine::set([
                    'hook_flags' => \OpenSwoole\Runtime::HOOK_TCP,
                ]);
                return;
            }

            // Swoole fallback (if you're not actually on OpenSwoole)
            if (class_exists(\Swoole\Coroutine::class) && class_exists(\Swoole\Runtime::class)) {
                \Swoole\Coroutine::set([
                    'hook_flags' => \Swoole\Runtime::HOOK_TCP,
                ]);
            }
        } catch (\Throwable) {
            // If hooks can't be enabled here, subscriber may block; bootstrap is the correct place.
        }
    }

    private function handleMessage(string $raw): void
    {
        $env = json_decode($raw, true);
        if (!is_array($env)) return;

        $payload = $env['payload'] ?? null;
        if (!is_array($payload)) return;

        $fds = $this->resolveTargets($env);
        if (!$fds) return;

        $data = $this->encodePayload($payload);
        if ($data === null) return;

        foreach ($fds as $fd) {
            if (!$this->server->isEstablished($fd)) continue;

            $this->server->push($fd, $data);

            // Optionally track activity:
            $this->store->touch($fd);
        }
    }

    /** @return int[] */
    private function resolveTargets(array $env): array
    {
        $type   = $env['target_type'] ?? '';
        $target = $env['target'] ?? [];

        if ($type === 'fd') {
            $fd = (int) ($target['fd'] ?? 0);
            return $fd > 0 ? [$fd] : [];
        }

        if ($type === 'meta') {
            $key   = (string) ($target['key'] ?? '');
            $value = ($target['value'] ?? null);

            if ($key === '' || $value === null) return [];

            return $this->store->fdsWhereMeta($key, $value);
        }

        return [];
    }

    private function encodePayload(array $payload): ?string
    {
        return match ($payload['kind'] ?? null) {
            'event' => Protocol::encodeEvent(
                (string) ($payload['event'] ?? ''),
                (array)  ($payload['data'] ?? []),
                (array)  ($payload['meta'] ?? [])
            ),
            'cmd' => Protocol::encodeCmd(
                (string) ($payload['cmd'] ?? ''),
                (array)  ($payload['payload'] ?? [])
            ),
            default => null,
        };
    }
}
