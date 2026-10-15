<?php

declare(strict_types=1);

namespace RustServerWiper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

final class HealthProbe implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(public int $serverId, public string $id, string $targetQueue = 'standard')
    {
        $this->onQueue($targetQueue);
    }

    public function handle(): void
    {
        Cache::put('rust-server-wiper:probe:'.$this->serverId.':'.$this->id, now('UTC')->toAtomString(), 300);
    }
}
