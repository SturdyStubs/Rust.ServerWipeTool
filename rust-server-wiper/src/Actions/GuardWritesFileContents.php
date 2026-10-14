<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\WritesFileContents;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardWritesFileContents implements WritesFileContents
{
    public function __construct(private WritesFileContents $inner) {}

    public function write(Server $server, string $path, string $contents): void
    {
        Maintenance::guard($server->id);
        $this->inner->write($server, $path, $contents);
    }
}
