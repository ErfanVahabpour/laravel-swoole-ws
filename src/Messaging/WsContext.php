<?php

namespace EFive\Ws\Messaging;

use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;
use EFive\Ws\Contracts\ConnectionStore;

final class WsContext
{
    public function __construct(
        public readonly Server $server,
        public readonly Frame $frame,
        public readonly ConnectionStore $store,
        public readonly ?object $user = null,
        public readonly ?WsMessage $message = null,
    ) {}

    public function withUser(?object $user): self
    {
        return new self($this->server, $this->frame, $this->store, $user, $this->message);
    }

    public function withMessage(WsMessage $message): self
    {
        return new self($this->server, $this->frame, $this->store, $this->user, $message);
    }

    public function fd(): int { return (int) $this->frame->fd; }

    /** Whether the current connection is still established. */
    public function isEstablished(): bool
    {
        return $this->server->isEstablished($this->fd());
    }

    /**
     * Close the current WebSocket connection.
     *
     * - On Swoole/OpenSwoole that support it, this will use `disconnect($fd, $code, $reason)`.
     * - Otherwise it falls back to `close($fd)`.
     *
     * @param int $code WebSocket close code (default: 1000 = normal closure)
     * @param string $reason Optional close reason (may be ignored depending on runtime)
     */
    public function disconnect(int $code = 1000, string $reason = ''): bool
    {
        $fd = $this->fd();

        if (! $this->server->isEstablished($fd)) {
            return false;
        }

        // OpenSwoole/Swoole websocket server exposes disconnect in many builds.
        if (method_exists($this->server, 'disconnect')) {
            // Some builds accept (fd) only, others (fd, code, reason)
            try {
                return (bool) $this->server->disconnect($fd, $code, $reason);
            } catch (\Throwable) {
                return (bool) $this->server->disconnect($fd);
            }
        }

        return (bool) $this->server->close($fd);
    }

    /** Alias of disconnect() for readability. */
    public function close(int $code = 1000, string $reason = ''): bool
    {
        return $this->disconnect($code, $reason);
    }

    /**
     * Disconnect and immediately remove the fd from the store.
     *
     * Note: onClose will also call removeFd(); this is safe but optional.
     */
    public function disconnectAndForget(int $code = 1000, string $reason = ''): bool
    {
        $fd = $this->fd();
        $ok = $this->disconnect($code, $reason);
        $this->store->removeFd($fd);
        return $ok;
    }

    public function emit(string $event, array $data = [], array $meta = []): void
    {
        $this->server->push($this->fd(), Protocol::encodeEvent($event, $data, $meta));
    }

    public function respond(mixed $payload, array $meta = []): void
    {
        $this->server->push($this->fd(), Protocol::encodeResponse($payload, $meta));
    }

    // Room/channel helpers (basic)
    public function join(string $room): void { $this->store->join($room, $this->fd()); }
    public function leave(string $room): void { $this->store->leave($room, $this->fd()); }

    public function broadcastTo(string $room, string $event, array $data = [], array $meta = [], bool $excludeSelf = true): void
    {
        $fds = $this->store->members($room);
        foreach ($fds as $fd) {
            if ($excludeSelf && $fd === $this->fd()) continue;
            if ($this->server->isEstablished($fd)) {
                $this->server->push($fd, Protocol::encodeEvent($event, $data, $meta));
            }
        }
    }

    public function pushCmd(string $cmd, array $payload = []): void
    {
        $this->server->push($this->fd(), Protocol::encodeCmd($cmd, $payload));
    }

    public function replyRet(string $ret, bool $result, array $payload = []): void
    {
        $this->server->push($this->fd(), Protocol::encodeRet($ret, $result, $payload));
    }


    public function setMeta(string $key, string|int|float|bool|null $value): void
    {
        $this->store->setMeta($this->fd(), $key, $value);
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->store->getMeta($this->fd(), $key, $default);
    }

    public function meta(): array
    {
        return $this->store->meta($this->fd());
    }

    public function forgetMeta(?string $key = null): void
    {
        $this->store->forgetMeta($this->fd(), $key);
    }

    public function bindUser(int|string $userId): void
    {
        $this->store->bindUser($this->fd(), $userId);
    }

    public function userId(): int|string|null
    {
        return $this->store->userId($this->fd());
    }
}
