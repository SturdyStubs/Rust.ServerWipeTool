<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\ChangesServerEgg;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardChangesServerEgg implements ChangesServerEgg
{
    public function __construct(private ChangesServerEgg $inner) {}

    public function change(Server $server, Egg $egg, bool $keepVariables = false): Server
    {
        Maintenance::guard($server->id);

        return $this->inner->change($server, $egg, $keepVariables);
    }
}
