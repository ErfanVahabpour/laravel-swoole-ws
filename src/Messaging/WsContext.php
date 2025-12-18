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
}
