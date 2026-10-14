<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\SendsServerCommands;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardSendsServerCommands implements SendsServerCommands
{
    public function __construct(private SendsServerCommands $inner) {}

    public function send(Server $server, string $command): void
    {
        Maintenance::guard($server->id);
        $this->inner->send($server, $command);
    }
}
