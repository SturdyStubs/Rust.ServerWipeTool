<?php
declare(strict_types=1);
namespace RustServerWiper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class ExecuteWipe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $tries = 1;
    public int $timeout = 600;
    public bool $failOnTimeout = true;
    public function __construct(public int $runId) { $this->onQueue('standard'); }

    public function handle(WipeEngine $engine): void
    {
        $run = Run::query()->find($this->runId);
        if (!$run || $run->status !== 'queued') return;
        $lock = Cache::lock('rust-server-wiper:server:'.$run->server_id, 900);
        if (!$lock->get()) return; // The scheduler redispatches queued runs after the lock expires.
        try {
            if (Run::query()->whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now('UTC')]) !== 1) return;
            $allowed = fn () => Availability::enabled() && (!$run->wipe_id || Wipe::query()->whereKey($run->wipe_id)->where('enabled', true)->exists());
            if (!$allowed()) {
                $run->update(['status' => 'cancelled', 'message' => 'Extension or schedule disabled.', 'finished_at' => now('UTC')]);
                return;
            }
            $result = $engine->execute($run->server_id, $run->payload, $run->timezone,
                WipeMap::query()->where('server_id', $run->server_id)->pluck('url')->all(), $allowed,
                function ($message, $result = null) use ($run): void { $run->update(['message' => $message, ...($result === null ? [] : ['result' => $result])]); });
            $run->update(['status' => 'completed', 'message' => 'Wipe completed; start signal sent.', 'result' => $result, 'finished_at' => now('UTC')]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'message' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now('UTC')]);
            report($e);
        } finally { $lock->release(); }
    }

    public function failed(?Throwable $e): void
    {
        Run::query()->whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'message' => mb_substr($e?->getMessage() ?? 'Worker stopped. Inspect the server before retrying.', 0, 2000), 'finished_at' => now('UTC'),
        ]);
    }
}
