<?php

namespace EFive\Ws\Channels;

use Illuminate\Support\Facades\Auth;

final readonly class LaravelChannelAuthorizer
{
    public function __construct(
        private ChannelRegistry $registry,
        private string          $guardName
    )
    {
    }

    public function authorize(?object $user, string $channel): array
    {
        $match = $this->registry->match($channel);

        if (!$match) {
            return ['ok' => false, 'reason' => 'CHANNEL_NOT_DEFINED'];
        }

        [$def, $params] = $match;

        $res = ($def->authorizer)($user, ...array_values($params));

        if ($res === true) return ['ok' => true];
        if (is_array($res)) return ['ok' => true, 'presence' => $res];

        return ['ok' => false, 'reason' => 'FORBIDDEN'];
    }

    public function resolveUserFromToken(string $token): ?object
    {
        // 1) Custom resolver always wins
        $resolver = config('ws.auth.resolver');
        if (is_callable($resolver)) {
            return $resolver($token);
        }

        // 2) If Sanctum is installed, try PersonalAccessToken
        if (class_exists(\Laravel\Sanctum\PersonalAccessToken::class)) {
            $pat = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
            return $pat?->tokenable;
        }

        // 3) Otherwise, do not attempt session guard hacks
        // (SessionGuard doesn't support setToken)
        return null;
    }
}
