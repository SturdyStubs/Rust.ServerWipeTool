<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\DecompressesFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardDecompressesFiles implements DecompressesFiles
{
    public function __construct(private DecompressesFiles $inner) {}

    public function decompress(Server $server, ?string $root, string $file): void
    {
        Maintenance::guard($server->id);
        $this->inner->decompress($server, $root, $file);
    }
}
