<?php

namespace EFive\Ws\Stores;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use EFive\Ws\Contracts\ConnectionStore;

final class RedisConnectionStore implements ConnectionStore
{
    private Connection $redis;
    private string $prefix;
    private int $ttl;

    public function __construct(string $connection = 'default', string $prefix = 'ws:', int $ttlSeconds = 86400)
    {
        $this->redis = Redis::connection($connection);
        $this->prefix = $prefix;
        $this->ttl = $ttlSeconds;
    }

    public function addFd(int $fd): void
    {
        $now = time();
        $this->redis->sadd($this->k('fds'), $fd);
        $this->redis->expire($this->k('fds'), $this->ttl);

        $this->setConnectedAt($fd, $now);
        $this->touch($fd, $now);
    }

    public function allFds(): array
    {
        $raw = $this->redis->smembers($this->k('fds')) ?? [];
        $fds = array_values(array_map('intval', $raw));
        sort($fds);
        return $fds;
    }

    public function clearAllFds(): void
    {
        $fds = $this->allFds();
        $this->redis->del($this->k('fds'));

        foreach ($fds as $fd) {
            $this->forgetMeta($fd);
            $this->redis->del(
                $this->k("fd:{$fd}:connected_at"),
                $this->k("fd:{$fd}:last_seen_at")
            );
        }
    }

    public function setConnectedAt(int $fd, int $unixSeconds): void
    {
        $this->redis->setex($this->k("fd:{$fd}:connected_at"), $this->ttl, (string)$unixSeconds);
    }

    public function connectedAt(int $fd): ?int
    {
        $v = $this->redis->get($this->k("fd:{$fd}:connected_at"));
        return $v !== null ? (int)$v : null;
    }

    public function touch(int $fd, ?int $unixSeconds = null): void
    {
        $ts = $unixSeconds ?? time();
        $this->redis->setex($this->k("fd:{$fd}:last_seen_at"), $this->ttl, (string)$ts);
        $this->redis->expire($this->k('fds'), $this->ttl);
    }

    public function lastSeenAt(int $fd): ?int
    {
        $v = $this->redis->get($this->k("fd:{$fd}:last_seen_at"));
        return $v !== null ? (int)$v : null;
    }

    public function bindUser(int $fd, int|string $userId): void
    {
        $this->addFd($fd);
        $this->redis->setex($this->k("fd:{$fd}:user"), $this->ttl, (string)$userId);
        $this->redis->sadd($this->k("user:{$userId}:fds"), $fd);
        $this->redis->expire($this->k("user:{$userId}:fds"), $this->ttl);
    }

    public function userId(int $fd): int|string|null
    {
        $v = $this->redis->get($this->k("fd:{$fd}:user"));
        return $v !== null ? (string)$v : null;
    }

    public function setHandshakeToken(int $fd, ?string $token): void
    {
        $key = $this->k("fd:{$fd}:token");
        if ($token === null || $token === '') {
            $this->redis->del($key);
            return;
        }
        $this->redis->setex($key, $this->ttl, $token);
    }

    public function handshakeToken(int $fd): ?string
    {
        $v = $this->redis->get($this->k("fd:{$fd}:token"));
        return $v !== null ? (string)$v : null;
    }

    public function join(string $room, int $fd): void
    {
        $this->redis->sadd($this->k("room:{$room}:fds"), $fd);
        $this->redis->expire($this->k("room:{$room}:fds"), $this->ttl);

        $this->redis->sadd($this->k("fd:{$fd}:rooms"), $room);
        $this->redis->expire($this->k("fd:{$fd}:rooms"), $this->ttl);
    }

    public function leave(string $room, int $fd): void
    {
        $this->redis->srem($this->k("room:{$room}:fds"), $fd);
        $this->redis->srem($this->k("fd:{$fd}:rooms"), $room);
    }

    public function members(string $room): array
    {
        $raw = $this->redis->smembers($this->k("room:{$room}:fds")) ?? [];
        return array_values(array_map('intval', $raw));
    }

    public function fdsForUser(int|string $userId): array
    {
        $raw = $this->redis->smembers($this->k("user:{$userId}:fds")) ?? [];
        return array_values(array_map('intval', $raw));
    }

    public function removeFd(int $fd): void
    {
        $userId = $this->userId($fd);

        // remove from active fd index
        $this->redis->srem($this->k('fds'), $fd);

        // remove fd from rooms
        $rooms = $this->redis->smembers($this->k("fd:{$fd}:rooms"));
        if (!is_array($rooms)) $rooms = [];
        foreach ($rooms as $room) {
            $this->redis->srem($this->k("room:{$room}:fds"), $fd);
        }

        // remove from user fds
        if ($userId !== null) {
            $this->redis->srem($this->k("user:{$userId}:fds"), $fd);
        }

        $this->forgetMeta($fd);

        // cleanup fd keys
        $this->redis->del(
            $this->k("fd:{$fd}:rooms"),
            $this->k("fd:{$fd}:user"),
            $this->k("fd:{$fd}:token"),
            $this->k("fd:{$fd}:path"),
            $this->k("fd:{$fd}:connected_at"),
            $this->k("fd:{$fd}:last_seen_at")
        );
    }

    private function k(string $suffix): string
    {
        return $this->prefix . $suffix;
    }

    public function setHandshakePath(int $fd, ?string $path): void
    {
        $key = $this->k("fd:{$fd}:path");

        if ($path === null || $path === '') {
            $this->redis->del($key);
            return;
        }

        $this->redis->setex($key, $this->ttl, $path);
    }

    public function handshakePath(int $fd): ?string
    {
        $value = $this->redis->get($this->k("fd:{$fd}:path"));
        return $value !== null ? (string)$value : null;
    }

    private function metaKey(int $fd): string
    {
        return $this->k("fd:{$fd}:meta");
    }

    private function metaIndexKey(string $key, string $value): string
    {
        // keep it safe; value can contain ':' so we don't split, just embed
        return $this->k("meta:{$key}:" . md5($value) . ":fds");
    }

    private function normalizeMetaValue(string|int|float|bool|null $value): ?string
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value ? '1' : '0';
        return (string)$value;
    }

    public function setMeta(int $fd, string $key, string|int|float|bool|null $value): void
    {
        $key = trim($key);
        if ($key === '') return;

        $v = $this->normalizeMetaValue($value);

        // remove old index if key existed
        $old = $this->redis->hget($this->metaKey($fd), $key);
        if ($old !== null && $old !== '') {
            $this->redis->srem($this->metaIndexKey($key, (string)$old), $fd);
        }

        if ($v === null) {
            // delete field
            $this->redis->hdel($this->metaKey($fd), $key);
            return;
        }

        // write hash + index
        $this->redis->hset($this->metaKey($fd), $key, $v);
        $this->redis->expire($this->metaKey($fd), $this->ttl);

        $this->redis->sadd($this->metaIndexKey($key, $v), $fd);
        $this->redis->expire($this->metaIndexKey($key, $v), $this->ttl);

        // keep fds set alive too (so ws:list + indexes don't expire while active)
        $this->redis->expire($this->k('fds'), $this->ttl);
    }

    public function getMeta(int $fd, string $key, mixed $default = null): mixed
    {
        $v = $this->redis->hget($this->metaKey($fd), $key);
        return $v === null ? $default : $v;
    }

    public function meta(int $fd): array
    {
        $m = $this->redis->hgetall($this->metaKey($fd)) ?? [];
        // Predis returns associative array already
        return is_array($m) ? $m : [];
    }

    public function forgetMeta(int $fd, ?string $key = null): void
    {
        if ($key === null) {
            // remove all fields + indexes (best effort)
            $all = $this->meta($fd);
            foreach ($all as $k => $v) {
                $this->redis->srem($this->metaIndexKey((string)$k, (string)$v), $fd);
            }
            $this->redis->del($this->metaKey($fd));
            return;
        }

        $old = $this->redis->hget($this->metaKey($fd), $key);
        if ($old !== null && $old !== '') {
            $this->redis->srem($this->metaIndexKey($key, (string)$old), $fd);
        }
        $this->redis->hdel($this->metaKey($fd), $key);
    }

    public function fdsWhereMeta(string $key, string|int|float|bool $value): array
    {
        $v = $this->normalizeMetaValue($value);
        if ($v === null) return [];

        $raw = $this->redis->smembers($this->metaIndexKey($key, $v)) ?? [];
        $fds = array_values(array_map('intval', $raw));
        sort($fds);
        return $fds;
    }
}
