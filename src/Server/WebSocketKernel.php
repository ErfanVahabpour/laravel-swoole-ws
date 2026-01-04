<?php

namespace EFive\Ws\Server;

use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use EFive\Ws\Messaging\MessageDispatcher;
use EFive\Ws\Messaging\WsContext;
use EFive\Ws\Contracts\ConnectionStore;

final readonly class WebSocketKernel
{
    public function __construct(
        private MessageDispatcher $dispatcher,
        private ConnectionStore   $store,
    )
    {
    }

    public function onOpen(Server $server, Request $request): void
    {

        $fd = (int)$request->fd;
        $key = (string)config('ws.auth.handshake_query_key', 'token');

        $token = null;
        if (isset($request->get) && is_array($request->get) && isset($request->get[$key])) {
            $token = (string)$request->get[$key];
        }

        $this->store->setHandshakeToken($fd, $token);

        // Register connection for ws:list and track connection age.
        $this->store->addFd($fd);

        $uri = '/';
        if (isset($request->server['request_uri'])) {
            $uri = (string)$request->server['request_uri'];
        }
        $this->store->setHandshakePath($fd, $uri);
    }

    public function onMessage(Server $server, Frame $frame): void
    {
        // Track last activity for ws:list
        $this->store->touch((int)$frame->fd);

        $ctx = new WsContext($server, $frame, $this->store);

        // Example: resolve auth token from message meta
        // (you can also implement ws.auth middleware)
        $this->dispatcher->dispatch($ctx);
    }

    public function onClose(Server $server, int $fd): void
    {
        $this->store->removeFd($fd);
    }

    public function onWorkerStart(Server $server, int $workerId): void
    {
        if (!config('ws.bus.enabled', true)) return;

        if ($workerId !== 0) {
            return;
        }

        // start Redis push subscriber only in worker processes
        if ($server->taskworker ?? false) {
            return;
        }

        $subscriber = new WsBusSubscriber(
            $server,
            app(ConnectionStore::class)
        );

        $subscriber->start();
    }
}
