<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\UpdatesServerStartup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use RustServerWiper\Services\Maintenance;

final class GuardUpdatesServerStartup implements UpdatesServerStartup
{
    public function __construct(private UpdatesServerStartup $inner) {}

    public function update(Server $server, array $input, int $userLevel = User::USER_LEVEL_USER): Server
    {
        Maintenance::guard($server->id);

        return $this->inner->update($server, $input, $userLevel);
    }
}
