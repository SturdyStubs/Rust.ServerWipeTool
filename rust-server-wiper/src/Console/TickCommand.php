<?php

declare(strict_types=1);

namespace RustServerWiper\Console;

use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RustServerWiper\Jobs\ExecuteWipe;
use RustServerWiper\Jobs\RestoreWipe;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Notifications;
use RustServerWiper\Services\Recurrence;
use Throwable;

final class TickCommand extends Command
{
    protected $signature = 'rust-server-wiper:tick';

    protected $description = 'Queue due wipes and send scheduled announcements.';

    public function handle(Gateway $gateway): int
    {
        $lock = Cache::lock('rust-server-wiper:tick', 120);
        if (! $lock->get()) {
            return 0;
        }
        $errors = false;
        try {
            $now = now('UTC')->startOfMinute();
            Wipe::query()->where('enabled', true)->whereNotNull('next_run_at')->where('next_run_at', '<=', $now->copy()->addDay())
                ->orderBy('id')->chunkById(100, function ($wipes) use ($gateway, $now, &$errors): void {
                    foreach ($wipes as $wipe) {
                        try {
                            $due = $wipe->next_run_at;
                            if ($due > $now) {
                                $options = Settings::forServer($wipe->server_id)->options ?? [];
                                if ($due->subMinutes($options['discord_minutes'] ?? 30)->equalTo($now)) {
                                    app(Notifications::class)->send($wipe->server_id, 'upcoming', 'wipe:'.$wipe->id.':'.$due->timestamp, ['label' => $wipe->label, 'time' => $due->setTimezone($wipe->timezone)->format('Y-m-d H:i T'), 'message' => 'Upcoming wipe at '.$due->setTimezone($wipe->timezone)->format('Y-m-d H:i T')]);
                                }
                                foreach ($wipe->payload['commands'] ?? [] as $i => $command) {
                                    if ($due->subMinutes($command['minutes'])->equalTo($now)) {
                                        // Mark first: a response lost after Wings accepts a command must not duplicate it.
                                        $key = 'rust-server-wiper:notice:'.$wipe->id.':'.$due->timestamp.':'.$i;
                                        if (Cache::add($key, true, 172800)) {
                                            $gateway->assertReady($wipe->server_id);
                                            $gateway->commands($wipe->server_id, $command['command']);
                                        }
                                    }
                                }

                                continue;
                            }
                            DB::transaction(function () use ($wipe, $now): void {
                                $current = Wipe::query()->whereKey($wipe->id)->lockForUpdate()->first();
                                if (! $current || ! $current->enabled || ! $current->next_run_at || $current->next_run_at > $now) {
                                    return;
                                }
                                $late = $current->next_run_at < $now->copy()->subMinutes(15);
                                Run::query()->firstOrCreate(['wipe_id' => $current->id, 'due_at' => $current->next_run_at], [
                                    'server_id' => $current->server_id, 'label' => $current->label, 'payload' => $current->payload, 'timezone' => $current->timezone,
                                    'created_by' => $current->created_by,
                                    'updated_by' => $current->updated_by,
                                    'status' => $late ? 'missed' : 'queued', 'message' => $late ? 'More than 15 minutes late; skipped. Review and run manually.' : null,
                                    'finished_at' => $late ? $now : null,
                                ]);
                                $next = Recurrence::next($current->repeat, $current->timezone, DateTimeImmutable::createFromInterface($now));
                                $current->update(['next_run_at' => $next]);
                            });
                        } catch (Throwable $e) {
                            $errors = true;
                            report($e);
                            $this->error('Wipe '.$wipe->id.': '.$e->getMessage());
                        }
                    }
                });
            // A killed worker is never replayed automatically after partial deletion.
            Run::query()->where('status', 'running')->where('started_at', '<', $now->copy()->subMinutes(25))->update([
                'status' => 'failed', 'message' => 'Worker did not finish. Inspect the server before another wipe.', 'finished_at' => $now,
            ]);
            Run::query()->where('status', 'queued')->where('created_at', '<', $now->copy()->subMinutes(15))->update([
                'status' => 'missed', 'message' => 'Queue execution was delayed over 15 minutes; skipped.', 'finished_at' => $now,
            ]);
            Run::query()->whereIn('status', ['queued', 'deferred'])->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', $now))->orderBy('id')->chunkById(100, function ($runs): void {
                foreach ($runs as $run) {
                    ExecuteWipe::dispatch($run->id);
                }
            });
            Restoration::query()->where('status', 'queued')->where('created_at', '<', $now->copy()->subMinutes(15))->update(['status' => 'failed', 'message' => 'Restore queue was delayed; request a new restore.', 'finished_at' => $now]);
            Restoration::query()->where('status', 'queued')->each(fn ($restore) => RestoreWipe::dispatch($restore->id));
        } finally {
            $lock->release();
        }

        return $errors ? 1 : 0;
    }
}
