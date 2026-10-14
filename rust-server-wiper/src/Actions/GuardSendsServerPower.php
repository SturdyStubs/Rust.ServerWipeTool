<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardSendsServerPower implements SendsServerPower
{
    public function __construct(private SendsServerPower $inner) {}

    public function send(Server $server, string $signal): void
    {
        Maintenance::guard($server->id);
        $this->inner->send($server, $signal);
    }
}
