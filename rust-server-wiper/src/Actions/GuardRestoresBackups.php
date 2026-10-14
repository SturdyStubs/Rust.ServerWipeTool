<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Backups\RestoresBackups;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use RustServerWiper\Services\Maintenance;

final class GuardRestoresBackups implements RestoresBackups
{
    public function __construct(private RestoresBackups $inner) {}

    public function restore(Server $server, Backup $backup, User $user, bool $truncate): void
    {
        Maintenance::guard($server->id);
        $this->inner->restore($server, $backup, $user, $truncate);
    }
}
