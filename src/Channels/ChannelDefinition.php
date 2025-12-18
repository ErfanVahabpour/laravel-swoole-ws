<?php

namespace EFive\Ws\Channels;

use Closure;

final readonly class ChannelDefinition
{
    public function __construct(
        public string $pattern,
        public Closure $authorizer,
    ) {}
    public static function make(string $pattern, callable $authorizer): self
    {
        return new self(
            $pattern,
            $authorizer instanceof Closure ? $authorizer : $authorizer(...)
        );
    }

    public function match(string $channelName): ?array
    {
        // pattern example: private-chat.{chatId}
        $quoted = preg_quote($this->pattern, '/');

        // convert \{chatId\} => (?P<chatId>[^.]+)
        $regexBody = preg_replace(
            '/\\\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\\\}/',
            '(?P<$1>[^.]+)',
            $quoted
        );

        $regex = '/^' . $regexBody . '$/';

        if (!preg_match($regex, $channelName, $m)) {
            return null;
        }

        // return only named params
        $params = [];
        foreach ($m as $k => $v) {
            if (is_string($k)) {
                $params[$k] = $v;
            }
        }

        return $params;
    }

}
