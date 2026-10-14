<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\ChangesFilePermissions;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardChangesFilePermissions implements ChangesFilePermissions
{
    public function __construct(private ChangesFilePermissions $inner) {}

    public function change(Server $server, ?string $root, array $files): void
    {
        Maintenance::guard($server->id);
        $this->inner->change($server, $root, $files);
    }
}
