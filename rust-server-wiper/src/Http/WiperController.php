<?php
declare(strict_types=1);
namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\ExecuteWipe;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Paths;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class WiperController
{
    public function __construct(private Gateway $gateway, private WipeEngine $engine) {}
    private function authorize(Request $request, Server $server): void
    {
        abort_unless($request->user()->can('ext.rust-server-wiper.manage', $server), 403);
        // No hard-coded nest ID: support standard and custom Rust eggs by their environment.
        $variables = $this->gateway->environment($server->id);
        abort_unless(isset($variables['WORLD_SEED'], $variables['WORLD_SIZE']), 422, 'Use a Rust egg with WORLD_SEED and WORLD_SIZE variables.');
    }
    private function wipe(Server $server, int $id): Wipe { return Wipe::query()->where('server_id', $server->id)->findOrFail($id); }
    public function index(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        return new JsonResponse([
            'environment' => $this->gateway->environment($server->id), 'timezones' => \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),
            'wipes' => Wipe::query()->where('server_id', $server->id)->orderBy('next_run_at')->get(),
            'maps' => WipeMap::query()->where('server_id', $server->id)->orderBy('name')->get(),
            'runs' => Run::query()->where('server_id', $server->id)->latest('id')->limit(50)->get(),
        ]);
    }
    public function store(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = WipeInput::parse($request);
        abort_if(Wipe::query()->where('server_id', $server->id)->count() >= 100, 422, 'Maximum 100 schedules per server.');
        $wipe = Wipe::query()->create(['server_id' => $server->id, ...$data]);
        return new JsonResponse($wipe, 201);
    }
    public function update(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $wipe = $this->wipe($server, $wipeId);
        $data = WipeInput::parse($request);
        DB::transaction(function () use ($wipe, $data): void {
            $current = Wipe::query()->whereKey($wipe->id)->lockForUpdate()->firstOrFail();
            abort_if(Run::query()->where('wipe_id', $wipe->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'This schedule has an active run. Wait for it to finish.');
            $current->update($data);
        });
        return new JsonResponse($wipe->fresh());
    }
    public function toggle(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $request->validate(['enabled' => 'required|boolean']);
        $wipe = $this->wipe($server, $wipeId);
        $wipe->update($data);
        if (!$data['enabled']) Run::query()->where('wipe_id', $wipeId)->where('status', 'queued')->update(['status' => 'cancelled', 'finished_at' => now('UTC'), 'message' => 'Schedule paused.']);
        return new JsonResponse($wipe->fresh());
    }
    public function delete(Request $request, Server $server, int $wipeId): JsonResponse
    {
        $this->authorize($request, $server);
        $wipe = $this->wipe($server, $wipeId);
        DB::transaction(function () use ($wipe): void {
            $current = Wipe::query()->whereKey($wipe->id)->lockForUpdate()->firstOrFail();
            abort_if(Run::query()->where('wipe_id', $wipe->id)->where('status', 'running')->exists(), 409, 'Wait for the running wipe to finish.');
            Run::query()->where('wipe_id', $wipe->id)->where('status', 'queued')->update(['status' => 'cancelled', 'message' => 'Schedule deleted.', 'finished_at' => now('UTC')]);
            $current->delete();
        });
        return new JsonResponse(null, 204);
    }
    public function preview(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = WipeInput::parse($request, true);
        try { return new JsonResponse($this->engine->preview($server->id, $data['payload'])); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['files' => $e->getMessage()]); }
    }
    public function run(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $request->validate(['confirm' => 'required|accepted']);
        $data = WipeInput::parse($request, true);
        $this->gateway->assertReady($server->id);
        $run = DB::transaction(function () use ($server, $data): Run {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            abort_if(Run::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'running'])->exists(), 409, 'A wipe is already queued or running for this server.');
            return Run::query()->create(['server_id' => $server->id, 'label' => $data['label'], 'payload' => $data['payload'], 'timezone' => $data['timezone'], 'due_at' => now('UTC')]);
        });
        ExecuteWipe::dispatch($run->id);
        return new JsonResponse($run, 202);
    }
    public function files(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $request->validate(['directory' => 'required|string|max:1000']);
        $directory = $data['directory'];
        if ($directory !== '/') {
            // Normalize a synthetic child to allow top-level browsing without allowing top-level deletion.
            try { Paths::normalize(rtrim($directory, '/').'/browse', false); }
            catch (Throwable $e) { throw ValidationException::withMessages(['directory' => $e->getMessage()]); }
        }
        return new JsonResponse(['files' => $this->gateway->list($server->id, $directory)]);
    }
    public function map(Request $request, Server $server): JsonResponse
    {
        $this->authorize($request, $server);
        $data = $request->validate(['name' => 'required|string|max:191', 'url' => 'required|url:http,https|max:2000']);
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
