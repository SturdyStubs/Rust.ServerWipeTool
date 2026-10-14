<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Extensions\ExtensionRepository;

final class Availability
{
    public static function enabled(): bool
    {
        return config('extensions.enabled') && Extension::query()->where('identifier', 'rust-server-wiper')->where('enabled', true)->exists()
            && app(ExtensionRepository::class)->isAvailable('rust-server-wiper');
    }
}
