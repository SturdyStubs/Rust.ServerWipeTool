<?php

declare(strict_types=1);

namespace RustServerWiper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\Permissions;
use Throwable;

final class RestoreWipe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $restoreId)
    {
        $this->onQueue('standard');
    }

    public function handle(Gateway $gateway): void
    {
        $restore = Restoration::query()->find($this->restoreId);
        if (! $restore || $restore->status !== 'queued') {
            return;
        }
        $lock = Cache::lock('rust-server-wiper:server:'.$restore->server_id, 1500);
        if (! $lock->get()) {
            return;
        }
        $operation = 'restore:'.$restore->id;
        $acquired = false;
        $requested = false;
        $finished = false;
        try {
            if (Restoration::query()->whereKey($restore->id)->where('status', 'queued')->update(['status' => 'stopping']) !== 1) {
                return;
            }
            if (! Availability::enabled()) {
                throw new \RuntimeException('Extension disabled.');
            }
            Permissions::authorize(User::query()->findOrFail($restore->created_by), Server::query()->findOrFail($restore->server_id), 'restore');
            $run = Run::query()->where('server_id', $restore->server_id)->findOrFail($restore->run_id);
            $gateway->assertReady($restore->server_id);
            if ($gateway->backupStatus($restore->server_id, $restore->backup_uuid) !== true) {
                throw new \RuntimeException('Backup is missing, incomplete or failed.');
            }
            $gateway->validateEnvironment($restore->server_id, $run->original_environment ?? []);
            Maintenance::acquire($restore->server_id, $operation);
            $acquired = true;
            Maintenance::internal($restore->server_id, function () use ($gateway, $restore, $run, &$requested, &$finished): void {
                if (! in_array($gateway->state($restore->server_id), ['offline', 'stopped'], true)) {
                    $gateway->power($restore->server_id, 'stop');
                } $stopped = false;
                $stopDeadline = hrtime(true) + 180_000_000_000;
                for ($i = 0; $i < 90 && hrtime(true) < $stopDeadline; $i++) {
                    if (in_array($gateway->state($restore->server_id), ['offline', 'stopped'], true)) {
                        $stopped = true;
                        break;
                    } $gateway->wait();
                }
                if (! $stopped) {
                    throw new \RuntimeException('Server did not stop. Restore was not started.');
                }
                $restore->update(['status' => 'restoring', 'message' => 'Restoring the full pre-wipe backup.']);
                $requested = true;
                $gateway->restoreStart($restore->server_id, $restore->backup_uuid, $restore->created_by);
                $done = false;
                $deadline = hrtime(true) + 600_000_000_000;
                for ($i = 0; $i < 300 && hrtime(true) < $deadline; $i++) {
                    $status = $gateway->restoreStatus($restore->server_id, $restore->backup_uuid);
                    if ($status === false) {
                        $finished = true;
                        throw new \RuntimeException('Wings reported that restore failed.');
                    }
                    if ($status === true) {
                        $done = true;
                        $finished = true;
                        break;
                    } $gateway->wait();
                }
                if (! $done) {
                    throw new \RuntimeException('Restore confirmation timed out. Maintenance remains until expiry; inspect Wings.');
                }
                $gateway->updateEnvironment($restore->server_id, $run->original_environment ?? []);
                $gateway->sync($restore->server_id);
                $restore->update(['status' => 'completed', 'message' => 'Backup and previous startup values restored. Server remains stopped.', 'finished_at' => now('UTC')]);
            });
        } catch (Throwable $e) {
            $restore->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now('UTC')]);
            report($e);
        } finally {
            if ($acquired && (! $requested || $finished)) {
                Maintenance::release($restore->server_id, $operation);
            } $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        Restoration::query()->whereKey($this->restoreId)->whereNotIn('status', ['completed', 'failed'])->update(['status' => 'failed', 'message' => 'Restore worker stopped. Inspect Wings before releasing maintenance.', 'finished_at' => now('UTC')]);
    }
}
