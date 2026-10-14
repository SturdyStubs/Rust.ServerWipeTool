<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\DetachesMountsFromServers;
use Pterodactyl\Models\Mount;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardDetachesMountsFromServers implements DetachesMountsFromServers
{
    public function __construct(private DetachesMountsFromServers $inner) {}

    public function detach(Server $server, Mount $mount): void
    {
        Maintenance::guard($server->id);
        $this->inner->detach($server, $mount);
    }
}
