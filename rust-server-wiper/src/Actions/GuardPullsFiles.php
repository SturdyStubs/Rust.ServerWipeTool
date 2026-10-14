<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Files\PullsFiles;
use Pterodactyl\Models\Server;
use RustServerWiper\Services\Maintenance;

final class GuardPullsFiles implements PullsFiles
{
    public function __construct(private PullsFiles $inner) {}

    public function pull(Server $server, string $url, ?string $directory, array $options): void
    {
        Maintenance::guard($server->id);
        $this->inner->pull($server, $url, $directory, $options);
    }
}
