<?php

namespace EFive\Ws\Messaging;

use EFive\Ws\Support\Json;

final class Protocol
{
    public static function decode(string $raw): WsMessage
    {
        $arr = Json::decodeArray($raw);

        return new WsMessage(
            path: (string)($arr['path'] ?? '/'),
            action: (string)($arr['action'] ?? ''),
            data: is_array($arr['data'] ?? null) ? $arr['data'] : [],
            meta: is_array($arr['meta'] ?? null) ? $arr['meta'] : [],
        );
    }

    public static function encodeEvent(string $event, array $data = [], array $meta = []): string
    {
        return Json::encode([
            'event' => $event,
            'data' => $data,
            'meta' => $meta,
        ]);
    }

    public static function encodeResponse(mixed $payload, array $meta = []): string
    {
        return self::encodeEvent('ws.response', ['payload' => $payload], $meta);
    }

    public static function encodeError(string $code, array $meta = [], array $extra = []): string
    {
        return self::encodeEvent('ws.error', array_merge(['code' => $code], $extra), $meta);
    }
}
