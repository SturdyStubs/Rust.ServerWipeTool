<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Pterodactyl\Models\Server;

final class Permissions
{
    public const KEYS = ['view', 'schedule', 'execute', 'restore', 'configure'];

    public static function can(object $user, Server $server, string $key): bool
    {
        if ($key === 'view') {
            foreach (self::KEYS as $other) {
                if ($other !== 'view' && $user->can('ext.rust-server-wiper.'.$other, $server)) {
                    return true;
                }
            }
        }

        return $user->can('ext.rust-server-wiper.'.$key, $server) ||
            (in_array($key, ['view', 'schedule', 'execute'], true) && $user->can('ext.rust-server-wiper.manage', $server));
    }

    public static function authorize(object $user, Server $server, string $key): void
    {
        abort_unless(self::can($user, $server, $key), 403);
    }

    public static function all(object $user, Server $server): array
    {
        return array_combine(self::KEYS, array_map(fn ($key) => self::can($user, $server, $key), self::KEYS));
    }
}
