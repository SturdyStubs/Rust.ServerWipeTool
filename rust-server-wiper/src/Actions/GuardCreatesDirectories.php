<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\CreatesDirectories;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardCreatesDirectories implements CreatesDirectories
{
    public function __construct(private CreatesDirectories $inner) {}

    public function create(Server $server, string $name, string $root): void
    {
        Maintenance::guard($server->id);
        $this->inner->create($server, $name, $root);
    }
}
