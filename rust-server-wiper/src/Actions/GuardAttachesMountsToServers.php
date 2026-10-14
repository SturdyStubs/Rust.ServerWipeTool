<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\AttachesMountsToServers;
use Pterodactyl\Models\Mount;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardAttachesMountsToServers implements AttachesMountsToServers
{
    public function __construct(private AttachesMountsToServers $inner) {}

    public function attach(Server $server, int $mountId): Mount
    {
        Maintenance::guard($server->id);

        return $this->inner->attach($server, $mountId);
    }
}
