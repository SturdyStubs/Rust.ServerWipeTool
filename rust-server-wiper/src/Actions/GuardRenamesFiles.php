<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\RenamesFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardRenamesFiles implements RenamesFiles
{
    public function __construct(private RenamesFiles $inner) {}

    public function rename(Server $server, ?string $root, array $files): void
    {
        Maintenance::guard($server->id);
        $this->inner->rename($server, $root, $files);
    }
}
