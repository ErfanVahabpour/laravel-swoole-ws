<?php

namespace EFive\Ws\Server;

use Swoole\WebSocket\Server;

final readonly class ServerFactory
{
    public function __construct(private WebSocketKernel $kernel)
    {
    }

    public function make(): Server
    {
        if (!class_exists(Server::class)) {
            throw new \RuntimeException('Swoole/OpenSwoole is not installed/enabled.');
        }

        $server = new Server(config('ws.host'), config('ws.port'));

        $settings = (array)config('ws.server', []);

        // OpenSwoole compatibility: remove unsupported ping options
        $isOpenSwoole = extension_loaded('openswoole')
            || class_exists(\OpenSwoole\WebSocket\Server::class, false)
            || str_starts_with(get_class($server), 'OpenSwoole\\');

        if ($isOpenSwoole) {
            unset(
                $settings['websocket_ping_interval'],
                $settings['websocket_ping_timeout'],
                $settings['open_websocket_ping_frame']
            );
        }


        $server->set($settings);

        $server->on('Open', [$this->kernel, 'onOpen']);
        $server->on('Message', [$this->kernel, 'onMessage']);
        $server->on('Close', [$this->kernel, 'onClose']);
        $server->on('WorkerStart', [$this->kernel, 'onWorkerStart']);

        return $server;
    }
}
