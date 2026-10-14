<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\CopiesFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardCopiesFiles implements CopiesFiles
{
    public function __construct(private CopiesFiles $inner) {}

    public function copy(Server $server, string $location): void
    {
        Maintenance::guard($server->id);
        $this->inner->copy($server, $location);
    }
}
