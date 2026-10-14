<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardDeletesFiles implements DeletesFiles
{
    public function __construct(private DeletesFiles $inner) {}

    public function delete(Server $server, ?string $root, array $files): void
    {
        Maintenance::guard($server->id);
        $this->inner->delete($server, $root, $files);
    }
}
