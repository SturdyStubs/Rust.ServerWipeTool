<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\ReinstallsServers;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardReinstallsServers implements ReinstallsServers
{
    public function __construct(private ReinstallsServers $inner) {}

    public function reinstall(Server $server): Server
    {
        Maintenance::guard($server->id);

        return $this->inner->reinstall($server);
    }
}
