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
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Announcements;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\Notifications;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\Reports;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class ExecuteWipe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId)
    {
        $this->onQueue('standard');
    }

    public function handle(WipeEngine $engine): void
    {
        $run = Run::query()->find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'deferred'], true) || ($run->not_before && $run->not_before->isFuture())) {
            return;
        }
        $lock = Cache::lock('rust-server-wiper:server:'.$run->server_id, 1500);
        if (! $lock->get()) {
            return;
        } // The scheduler redispatches queued runs after the lock expires.
        try {
            if (Run::query()->whereKey($run->id)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'running', 'started_at' => now('UTC')]) !== 1) {
                return;
            }
            $allowed = fn () => Availability::enabled() && (! $run->wipe_id || Wipe::query()->whereKey($run->wipe_id)->where('enabled', true)->exists());
            if (! $allowed()) {
                $run->update(['status' => 'cancelled', 'message' => 'Extension or schedule disabled.', 'finished_at' => now('UTC')]);

                return;
            }
            if (! $run->wipe_id && $run->created_by) {
                Permissions::authorize(User::query()->findOrFail($run->created_by), Server::query()->findOrFail($run->server_id), 'execute');
            }
            Reports::phase($run, 'preflight', 'Checking server state and execution policies.');
            if ($run->payload['wait_players'] ?? false) {
                $gateway = app(Gateway::class);
                $unknown = false;
                try {
                    $count = in_array($gateway->state($run->server_id), ['offline', 'stopped'], true) ? 0 : $gateway->playerCount($run->server_id);
                } catch (Throwable) {
                    $count = null;
                    $unknown = true;
                }
                $expired = now('UTC') >= $run->due_at->addMinutes($run->payload['max_deferral'] ?? 60);
                $policy = $unknown ? ($run->payload['unknown_players'] ?? 'defer') : ($count > 0 ? 'defer' : 'proceed');
                if ($policy !== 'proceed' && $expired) {
                    $policy = $run->payload['deadline_action'] ?? 'cancel';
                }
                if ($policy === 'cancel') {
                    $run->update(['status' => 'cancelled', 'message' => 'Player policy cancelled this wipe; count '.($count ?? 'unavailable').'.', 'finished_at' => now('UTC')]);

                    return;
                }
                if ($policy === 'defer') {
                    $run->update(['status' => 'deferred', 'phase' => 'players', 'not_before' => now('UTC')->addMinutes(5), 'started_at' => null, 'message' => $unknown ? 'Player count unavailable; retrying in five minutes.' : "$count players online; retrying in five minutes."]);

                    return;
                }
            }
            $operation = 'wipe:'.$run->id;
            Maintenance::acquire($run->server_id, $operation);
            $recent = Run::query()->where('server_id', $run->server_id)->whereIn('status', ['completed', 'startup_failed'])->latest('id')->limit(100)->get()->map(fn ($r) => $r->result['map_url'] ?? $r->result['environment']['MAP_URL'] ?? null)->filter()->values()->all();
            $result = Maintenance::internal($run->server_id, fn () => $engine->execute($run->server_id, $run->payload, $run->timezone,
                WipeMap::query()->where('server_id', $run->server_id)->get()->toArray(), $allowed,
                function ($message, $result = null, $metadata = []) use ($run): void {
                    if (isset($metadata['backup_uuid'])) {
                        $run->update(['backup_uuid' => $metadata['backup_uuid']]);
                    }
                    if (isset($metadata['original_environment'])) {
                        $run->update(['original_environment' => $metadata['original_environment']]);
                    }
                    $phase = str_contains(strtolower($message), 'backup') ? 'backup' : (str_contains(strtolower($message), 'readiness') ? 'readiness' : (str_contains(strtolower($message), 'delet') ? 'deleting' : 'execution'));
                    Reports::phase($run, $phase, $message, $result);
                    if (isset($metadata['event'])) {
                        app(Announcements::class)->run($run, $metadata['event']);
                    }
                }, ['run_id' => $run->id, 'recent_maps' => $recent, 'observe_running' => app(Announcements::class)->wantsRunning($run->server_id, $run->wipe_id)]));
            $timedOut = ($result['readiness'] ?? '') === 'timed_out';
            $run->update(['status' => $timedOut ? 'startup_failed' : 'completed', 'phase' => 'finished', 'message' => $timedOut ? 'Files wiped; readiness timed out. Inspect startup before another wipe.' : (($result['readiness'] ?? '') === 'rust_ready' ? 'Wipe completed; Rust is ready.' : 'Wipe completed; start signal accepted.'), 'result' => $result, 'finished_at' => now('UTC')]);
            if ($timedOut) {
                app(Announcements::class)->run($run, 'failed');
            }
            app(Notifications::class)->send($run->server_id, $timedOut ? 'failed' : 'completed', 'run:'.$run->id.':completed', ['label' => $run->label, 'message' => $run->message]);
            if (in_array($result['readiness'] ?? '', ['rust_ready', 'process_running'], true)) {
                app(Notifications::class)->send($run->server_id, 'ready', 'run:'.$run->id.':ready', ['label' => $run->label, 'message' => $run->message]);
            }
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'message' => mb_substr(Reports::redact($e->getMessage()), 0, 2000), 'finished_at' => now('UTC')]);
            app(Announcements::class)->run($run, 'failed');
            app(Notifications::class)->send($run->server_id, 'failed', 'run:'.$run->id.':failed', ['label' => $run->label, 'message' => $run->message]);
            report($e);
        } finally {
            if (isset($operation)) {
                Maintenance::release($run->server_id, $operation);
            } $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        Run::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'message' => mb_substr(Reports::redact($e?->getMessage() ?? 'Worker stopped. Inspect the server before retrying.'), 0, 2000), 'finished_at' => now('UTC'),
        ]);
        $run = Run::query()->find($this->runId);
        if ($run && $run->status === 'failed') {
            app(Announcements::class)->run($run, 'failed');
            app(Notifications::class)->send($run->server_id, 'failed', 'run:'.$run->id.':failed', ['label' => $run->label, 'message' => $run->message]);
        }
    }
}
