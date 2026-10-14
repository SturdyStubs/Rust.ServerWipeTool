<?php

declare(strict_types=1);

namespace RustServerWiper\Actions;

use Pterodactyl\Contracts\Backups\RestoresBackupStatuses;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Node;
use RustServerWiper\Models\Restoration;

final class RestoreResult implements RestoresBackupStatuses
{
    public function __construct(private RestoresBackupStatuses $inner) {}

    public function restoreStatus(string $backupUuid, Node $node, bool $successful): Backup
    {
        $backup = $this->inner->restoreStatus($backupUuid, $node, $successful);
        Restoration::query()->where('server_id', $backup->server_id)->where('backup_uuid', $backupUuid)->where('status', 'restoring')->update(['status' => $successful ? 'restored' : 'failed', 'message' => $successful ? 'Files restored; restoring startup values.' : 'Wings reported a failed restore.']);

        return $backup;
    }
}
