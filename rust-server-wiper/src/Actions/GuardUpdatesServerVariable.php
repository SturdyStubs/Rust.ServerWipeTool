<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardUpdatesServerVariable implements UpdatesServerVariable
{
    public function __construct(private UpdatesServerVariable $inner) {}

    public function update(Server $server, int $variableId, string $value): void
    {
        Maintenance::guard($server->id);
        $this->inner->update($server, $variableId, $value);
    }
}
