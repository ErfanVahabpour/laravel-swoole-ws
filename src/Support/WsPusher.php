<?php

namespace EFive\Ws\Support;

use Illuminate\Support\Facades\Redis;

final class WsPusher
{
    private string $targetType; // fd|meta
    private array $target;      // ['fd'=>12] or ['key'=>'sn','value'=>'ABC']
    private array $meta = [];

    private function __construct(string $targetType, array $target)
    {
        $this->targetType = $targetType;
        $this->target = $target;
    }

    public static function toFd(int $fd): self
    {
        return new self('fd', ['fd' => $fd]);
    }

    public static function toMeta(string $key, string|int|float|bool $value): self
    {
        return new self('meta', ['key' => $key, 'value' => (string) $value]);
    }

    /** optional: attach extra routing info */
    public function with(array $meta): self
    {
        $this->meta = $meta + $this->meta;
        return $this;
    }

    public function event(string $event, array $data = [], array $meta = []): void
    {
        $this->publish([
            'kind' => 'event',
            'event' => $event,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    public function cmd(string $cmd, array $payload = []): void
    {
        $this->publish([
            'kind' => 'cmd',
            'cmd' => $cmd,
            'payload' => $payload,
        ]);
    }

    private function publish(array $payload): void
    {
        $envelope = [
            'v' => 1,
            'target_type' => $this->targetType,
            'target' => $this->target,
            'payload' => $payload,
            'meta' => $this->meta,
            'ts' => time(),
        ];

        $channel = config('ws.bus.channel', 'ws:push');
        Redis::connection(config('ws.bus.connection', 'default'))
            ->publish($channel, json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
