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

        // Enable coroutine hooks for TCP clients (phpredis/predis) BEFORE workers run.
        // This prevents Redis subscribe from blocking the whole worker.
        try {
            if (class_exists(\OpenSwoole\Runtime::class)) {
                \OpenSwoole\Runtime::enableCoroutine(true, \OpenSwoole\Runtime::HOOK_TCP);
            } elseif (class_exists(\Swoole\Runtime::class)) {
                \Swoole\Runtime::enableCoroutine(true, \Swoole\Runtime::HOOK_TCP);
            }
        } catch (\Throwable) {
            // ignore; subscriber still guarded by workerId==0
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
