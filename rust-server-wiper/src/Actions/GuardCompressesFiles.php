<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\CompressesFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardCompressesFiles implements CompressesFiles
{
    public function __construct(private CompressesFiles $inner) {}

    public function compress(Server $server, ?string $root, array $files): array
    {
        Maintenance::guard($server->id);

        return $this->inner->compress($server, $root, $files);
    }
}
