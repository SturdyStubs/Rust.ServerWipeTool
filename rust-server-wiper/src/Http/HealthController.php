<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\HealthProbe;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class HealthController
{
    public function __construct(private Gateway $gateway, private WipeEngine $engine) {}

    private function authorize(Request $r, Server $server): void
    {
        abort_unless(Permissions::can($r->user(), $server, 'schedule') || Permissions::can($r->user(), $server, 'execute'), 403);
    }

    public function check(Request $r, Server $server): JsonResponse
    {
        $this->authorize($r, $server);
        $data = WipeInput::parse($r, true);
        $checks = [];
        $add = function (string $id, string $label, string $status, string $message) use (&$checks): void {
            $checks[] = compact('id', 'label', 'status', 'message');
        };
        $check = function (string $id, string $label, callable $work, string $recovery) use ($add): void {
            try {
                $add($id, $label, 'pass', $work());
            } catch (Throwable $e) {
                report($e);
                $add($id, $label, 'fail', $recovery);
            }
        };
        $add('extension', 'Extension availability', Availability::enabled() ? 'pass' : 'fail', Availability::enabled() ? 'The extension is enabled.' : 'Enable the extension before scheduling wipes.');
        $active = Maintenance::status($server->id) || Run::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'running', 'deferred'])->exists() || Restoration::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'stopping', 'restoring', 'restored'])->exists();
        $add('maintenance', 'Other operations', $active ? 'fail' : 'pass', $active ? 'Wait for the current wipe, restore, or maintenance operation to finish.' : 'No wipe, restore, or maintenance operation is active.');
        $state = null;
        $check('wings', 'Server & Wings connection', function () use ($server, &$state) {
            $this->gateway->assertReady($server->id);
            $state = $this->gateway->state($server->id);
            if (! in_array($state, ['running', 'offline', 'stopped'], true)) {
                throw new \RuntimeException('Server is transitioning.');
            }

            return 'Wings is reachable; server state: '.$state.'.';
        }, 'Check the node connection and server state in the panel, then run this check again.');
        $check('plan', 'Files, map & startup values', function () use ($server, $data) {
            $environment = $this->gateway->environment($server->id);
            if (! isset($environment['WORLD_SEED'], $environment['WORLD_SIZE'])) {
                throw new \RuntimeException('Incompatible egg.');
            }
            $maps = WipeMap::query()->where('server_id', $server->id)->get()->toArray();
            $this->engine->preflight($server->id, $data['payload'], $data['timezone'], $maps);
            $preview = $this->engine->preview($server->id, $data['payload']);

            return $preview['count'].' entries currently match. Paths and startup values passed validation.'.($data['payload']['random_level'] ? ' One possible rotation map was checked; selection is repeated when the wipe runs.' : ' The configured custom map URL, if any, was checked.');
        }, 'Review the Rust egg, identity directory, file patterns, startup fields, and custom map URL. Details are recorded in the panel logs.');
        if ($data['payload']['backup'] ?? false) {
            $check('backup', 'Backup quota', function () use ($server) {
                $capacity = $this->gateway->backupCapacity($server->id);
                if ($capacity['limit'] <= $capacity['used']) {
                    throw new \RuntimeException('Backup quota exhausted.');
                }

                return $capacity['used'].' of '.$capacity['limit'].' backup slots used. Storage availability is confirmed only when the backup runs.';
            }, 'Free a backup slot or ask your administrator to increase the quota before wiping.');
        } else {
            $add('backup', 'Backup protection', 'warning', 'No pre-wipe backup is selected. Deleted files cannot be recovered through this wipe.');
        }
        if (($data['payload']['wait_players'] ?? false) || ($data['payload']['readiness'] ?? 'none') === 'rcon') {
            if (in_array($state, ['offline', 'stopped'], true)) {
                $add('rcon', 'Rust WebRCON', 'warning', 'The server is stopped, so WebRCON cannot be verified. Recheck after starting Rust.');
            } else {
                $check('rcon', 'Rust WebRCON', fn () => $this->gateway->playerCount($server->id).' players online; WebRCON responded.', 'Check the saved WebRCON settings and worker access to the RCON port.');
            }
        }
        $heartbeat = Cache::get('rust-server-wiper:scheduler-heartbeat');
        $fresh = is_numeric($heartbeat) && now('UTC')->timestamp - (int) $heartbeat < 180;
        $add('scheduler', 'Minute scheduler', $fresh ? 'pass' : 'warning', $fresh ? 'The wipe scheduler completed a tick within the last three minutes.' : 'No recent scheduler tick was observed. Check the panel minute scheduler; this can also occur just after an upgrade.');
        $persistent = ! in_array(config('cache.default'), ['array', 'null'], true);
        $add('cache', 'Shared cache', $persistent ? 'pass' : 'fail', $persistent ? 'A persistent cache is configured. Web, scheduler, and worker processes must use the same cache.' : 'Configure a shared persistent cache. Array and null caches cannot coordinate wipes or worker probes.');
        $probeId = (string) Str::uuid();
        $check('worker', 'Queue worker', function () use ($server, $probeId) {
            HealthProbe::dispatch($server->id, $probeId);

            return 'A harmless probe was sent to the standard queue. Waiting for the worker to acknowledge it.';
        }, 'The worker probe could not be queued. Ask your administrator to check the queue connection.');
        foreach ($checks as &$item) {
            if ($item['id'] === 'plan' && $item['status'] === 'pass' && str_starts_with($item['message'], '0 entries')) {
                $item['status'] = 'warning';
            }
            if ($item['id'] === 'worker' && $item['status'] === 'pass') {
                $item['status'] = Cache::has('rust-server-wiper:probe:'.$server->id.':'.$probeId) ? 'pass' : 'pending';
                if ($item['status'] === 'pass') {
                    $item['message'] = 'The standard queue processed the probe.';
                }
                if (config('queue.default') === 'sync' || ! $persistent) {
                    $item['status'] = 'warning';
                    $item['message'] = 'A separate worker could not be verified. Use an asynchronous standard queue and shared persistent cache, then recheck.';
                }
            }
        }

        return new JsonResponse(['checked_at' => now('UTC')->toAtomString(), 'probe_id' => $probeId, 'checks' => $checks]);
    }

    public function probe(Request $r, Server $server, string $probeId): JsonResponse
    {
        $this->authorize($r, $server);
        abort_unless(Str::isUuid($probeId), 404);

        return new JsonResponse(['acknowledged' => Cache::has('rust-server-wiper:probe:'.$server->id.':'.$probeId)]);
    }
}
