<?php

namespace EFive\Ws\Tests;

use EFive\Ws\Facades\WS;

final class RouterTest extends TestCase
{
    public function test_it_registers_and_matches_routes(): void
    {
        WS::route('/chat', 'send', fn () => ['ok' => true]);

        $router = app('ws.router');
        $route = $router->match('/chat', 'send');

        $this->assertNotNull($route);
        $this->assertSame('/chat', $route->path);
        $this->assertSame('send', $route->action);
    }
}
