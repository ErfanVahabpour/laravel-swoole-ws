<?php

namespace EFive\Ws\Server;

use EFive\Ws\Contracts\ConnectionStore;
use EFive\Ws\Messaging\Protocol;
use Illuminate\Support\Facades\Redis;
use Swoole\WebSocket\Server;

final class WsBusSubscriber
{
    public function __construct(
        private Server $server,
        private ConnectionStore $store,
    ) {}

    public function start(): void
    {
        $channel = config('ws.bus.channel', 'ws:push');

        // Run the blocking subscribe in a coroutine-safe way
        go(function () use ($channel) {
            $redis = Redis::connection(config('ws.bus.connection', 'default'));
            $redis->subscribe([$channel], function ($message) {
                $this->handleMessage($message);
            });
        });
    }

    private function handleMessage(string $raw): void
    {
        $env = json_decode($raw, true);
        if (!is_array($env)) return;

        $payload = $env['payload'] ?? null;
        if (!is_array($payload)) return;

        $fds = $this->resolveTargets($env);
        if (!$fds) return;

        foreach ($fds as $fd) {
            if (!$this->server->isEstablished($fd)) continue;

            $data = $this->encodePayload($payload);
            if ($data === null) continue;

            $this->server->push($fd, $data);
            // Optionally track activity:
            $this->store->touch($fd);
        }
    }

    /** @return int[] */
    private function resolveTargets(array $env): array
    {
        $type = $env['target_type'] ?? '';
        $target = $env['target'] ?? [];

        if ($type === 'fd') {
            $fd = (int)($target['fd'] ?? 0);
            return $fd > 0 ? [$fd] : [];
        }

        if ($type === 'meta') {
            $key = (string)($target['key'] ?? '');
            $value = ($target['value'] ?? '');
            if ($key === '' || !array_key_exists('value', $target)) return [];
            return $this->store->fdsWhereMeta($key, $value);
        }

        return [];
    }

    private function encodePayload(array $payload): ?string
    {
        return match ($payload['kind'] ?? null) {
            'event' => Protocol::encodeEvent(
                (string)($payload['event'] ?? ''),
                (array)($payload['data'] ?? []),
                (array)($payload['meta'] ?? [])
            ),
            'cmd' => Protocol::encodeCmd(
                (string)($payload['cmd'] ?? ''),
                (array)($payload['payload'] ?? [])
            ),
            default => null,
        };
    }
}
