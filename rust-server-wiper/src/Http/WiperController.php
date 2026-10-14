<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\ExecuteWipe;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Calendar;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Identity;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\Paths;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class WiperController
{
    public function __construct(private Gateway $gateway, private WipeEngine $engine) {}

    private function authorize(Request $request, Server $server, string $permission = 'schedule'): void
    {
        if ($permission === 'preview') {
            abort_unless(Permissions::can($request->user(), $server, 'schedule') || Permissions::can($request->user(), $server, 'execute'), 403);
        } else {
            Permissions::authorize($request->user(), $server, $permission);
        }
        // No hard-coded nest ID: support standard and custom Rust eggs by their environment.
        $variables = $this->gateway->environment($server->id);
        abort_unless(isset($variables['WORLD_SEED'], $variables['WORLD_SIZE']), 422, 'Use a Rust egg with WORLD_SEED and WORLD_SIZE variables.');
    }

    private function wipe(Server $server, int $id): Wipe
    {
        return Wipe::query()->where('server_id', $server->id)->findOrFail($id);
    }

    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'view');

        return new JsonResponse([
            'environment' => array_intersect_key($this->gateway->environment($server->id), array_flip(['HOSTNAME', 'DESCRIPTION', 'WORLD_SEED', 'WORLD_SIZE', 'MAP_URL'])), 'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL),
            'wipes' => Wipe::query()->where('server_id', $server->id)->orderBy('next_run_at')->get(),
            'maps' => WipeMap::query()->where('server_id', $server->id)->orderBy('name')->get(),
            'runs' => Run::query()->where('server_id', $server->id)->latest('id')->limit(50)->get(),
            'permissions' => Permissions::all($request->user(), $server),
            'identity' => Identity::detect($server->startup ?? '', $this->gateway->environment($server->id)),
            'presets' => Preset::query()->where('server_id', $server->id)->get(),
            'calendar' => Calendar::forServer($server->id), 'maintenance' => Maintenance::status($server->id),
            'restores' => Restoration::query()->where('server_id', $server->id)->latest('id')->limit(20)->get(),
        ]);
    }

    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = WipeInput::parse($request);
        abort_if(Wipe::query()->where('server_id', $server->id)->count() >= 100, 422, 'Maximum 100 schedules per server.');
        $wipe = Wipe::query()->create(['server_id' => $server->id, 'created_by' => $request->user()->id ?? null, 'updated_by' => $request->user()->id ?? null, ...$data]);

        return new JsonResponse($wipe, 201);
    }

    public function update(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $wipe = $this->wipe($server, $wipeId);
        $data = WipeInput::parse($request);
        $data['updated_by'] = $request->user()->id ?? null;
        DB::transaction(function () use ($wipe, $data): void {
            $current = Wipe::query()->whereKey($wipe->id)->lockForUpdate()->firstOrFail();
            abort_if(Run::query()->where('wipe_id', $wipe->id)->whereIn('status', ['queued', 'running', 'deferred'])->exists(), 409, 'This schedule has an active run. Wait for it to finish.');
            $current->update($data);
        });

        return new JsonResponse($wipe->fresh());
    }

    public function toggle(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $request->validate(['enabled' => 'required|boolean']);
        $data['updated_by'] = $request->user()->id ?? null;
        $wipe = $this->wipe($server, $wipeId);
        $wipe->update($data);
        if (! $data['enabled']) {
            Run::query()->where('wipe_id', $wipeId)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled', 'finished_at' => now('UTC'), 'message' => 'Schedule paused.']);
        }

        return new JsonResponse($wipe->fresh());
    }

    public function delete(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $wipe = $this->wipe($server, $wipeId);
        DB::transaction(function () use ($wipe): void {
            $current = Wipe::query()->whereKey($wipe->id)->lockForUpdate()->firstOrFail();
            abort_if(Run::query()->where('wipe_id', $wipe->id)->where('status', 'running')->exists(), 409, 'Wait for the running wipe to finish.');
            Run::query()->where('wipe_id', $wipe->id)->whereIn('status', ['queued', 'deferred'])->update(['status' => 'cancelled', 'message' => 'Schedule deleted.', 'finished_at' => now('UTC')]);
            $current->delete();
        });

        return new JsonResponse(null, 204);
    }

    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'preview');
        $data = WipeInput::parse($request, true);
        try {
            return new JsonResponse($this->engine->preview($server->id, $data['payload']));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['files' => $e->getMessage()]);
        }
    }

    public function run(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'execute');
        $request->validate(['confirm' => 'required|accepted', 'request_uuid' => 'sometimes|uuid']);
        $data = WipeInput::parse($request, true);
        $this->gateway->assertReady($server->id);
        $run = DB::transaction(function () use ($server, $data, $request): Run {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            if ($request->input('request_uuid')) {
                $existing = Run::query()->where('server_id', $server->id)->where('request_uuid', $request->input('request_uuid'))->first();
                if ($existing) {
                    abort_if($existing->payload != $data['payload'], 409, 'This request ID was already used with different wipe settings.');

                    return $existing;
                }
            }
            Maintenance::guard($server->id);
            abort_if(Run::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'running', 'deferred'])->exists(), 409, 'A wipe is already queued or running for this server.');
            abort_if(Restoration::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'stopping', 'restoring', 'restored'])->exists(), 409, 'A restore is active.');

            return Run::query()->create(['server_id' => $server->id, 'request_uuid' => $request->input('request_uuid'), 'created_by' => $request->user()->id ?? null, 'label' => $data['label'], 'payload' => $data['payload'], 'timezone' => $data['timezone'], 'due_at' => now('UTC')]);
        });
        ExecuteWipe::dispatch($run->id);

        return new JsonResponse($run, 202);
    }

    public function files(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server, 'preview');
        $data = $request->validate(['directory' => 'required|string|max:1000']);
        $directory = $data['directory'];
        if ($directory !== '/') {
            // Normalize a synthetic child to allow top-level browsing without allowing top-level deletion.
            try {
                Paths::normalize(rtrim($directory, '/').'/browse', false);
            } catch (Throwable $e) {
                throw ValidationException::withMessages(['directory' => $e->getMessage()]);
            }
        }

        return new JsonResponse(['files' => $this->gateway->list($server->id, $directory)]);
    }

    public function map(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $request->validate(['name' => 'required|string|max:191', 'url' => 'required|url:http,https|max:2000', 'weight' => 'sometimes|integer|min:1|max:1000', 'position' => 'sometimes|integer|min:0|max:10000']);
        abort_if(WipeMap::query()->where('server_id', $server->id)->count() >= 100, 422, 'Maximum 100 maps per server.');

        return new JsonResponse(WipeMap::query()->create(['server_id' => $server->id, ...$data]), 201);
    }

    public function deleteMap(Request $request, Server $server, int $mapId): JsonResponse
    {
        $this->authorize($request, $server);
        WipeMap::query()->where('server_id', $server->id)->findOrFail($mapId)->delete();

        return new JsonResponse(null, 204);
    }
}
