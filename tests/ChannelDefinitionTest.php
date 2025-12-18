<?php

namespace EFive\Ws\Tests;

use EFive\Ws\Channels\ChannelDefinition;

final class ChannelDefinitionTest extends TestCase
{
    public function test_channel_pattern_matches_params(): void
    {
        $def = ChannelDefinition::make('private-chat.{chatId}', fn () => true);
        $params = $def->match('private-chat.123');

        $this->assertIsArray($params);
        $this->assertSame('123', $params['chatId']);
    }
}
