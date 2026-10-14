<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\RestoreWipe;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\Notifications;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\Reports;

final class FeaturesController
{
    public function savePreset(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        $input = $r->validate(['name' => 'required|string|max:191', 'payload' => 'required|array']);
        $data = WipeInput::parse(Request::create('/', 'POST', ['label' => $input['name'], 'timezone' => 'UTC', 'enabled' => false, 'repeat' => [], 'payload' => $input['payload']]), true);
        abort_if(Preset::query()->where('server_id', $server->id)->count() >= 100, 422, 'Maximum 100 presets.');

        return new JsonResponse(Preset::query()->create(['server_id' => $server->id, 'name' => $input['name'], 'payload' => $data['payload']]), 201);
    }

    public function updatePreset(Request $r, Server $server, int $presetId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        $preset = Preset::query()->where('server_id', $server->id)->findOrFail($presetId);
        $input = $r->validate(['name' => 'required|string|max:191', 'payload' => 'required|array']);
        $data = WipeInput::parse(Request::create('/', 'POST', ['label' => $input['name'], 'timezone' => 'UTC', 'enabled' => false, 'repeat' => [], 'payload' => $input['payload']]), true);
        $preset->update(['name' => $input['name'], 'payload' => $data['payload']]);

        return new JsonResponse($preset);
    }

    public function deletePreset(Request $r, Server $server, int $presetId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        Preset::query()->where('server_id', $server->id)->findOrFail($presetId)->delete();

        return new JsonResponse(null, 204);
    }

    public function settings(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');

        return new JsonResponse([...Settings::forServer($server->id)->publicData(), 'notifications' => DB::table('ext_rust_server_wiper_notifications')->where('server_id', $server->id)->latest('id')->limit(20)->get(['event_key', 'status', 'message', 'created_at'])]);
    }

    public function probe(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');

        return new JsonResponse(['players' => app(Gateway::class)->playerCount($server->id)]);
    }

    public function saveSettings(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        $data = $r->validate(['options' => 'present|array:rcon_port,rcon_tls,discord_events,discord_minutes,discord_template,discord_role',
            'options.rcon_port' => 'nullable|integer|min:1|max:65535', 'options.rcon_tls' => 'sometimes|boolean',
            'options.discord_events' => 'sometimes|array|max:4', 'options.discord_events.*' => Rule::in(['upcoming', 'completed', 'ready', 'failed']),
            'options.discord_minutes' => 'sometimes|integer|min:1|max:1440', 'options.discord_template' => 'sometimes|string|max:1500',
            'options.discord_role' => ['nullable', 'string', 'regex:/^[0-9]{1,20}$/D'],
            'discord_webhook' => 'nullable|string|max:250', 'rcon_password' => 'nullable|string|max:1000', 'clear_discord' => 'sometimes|boolean', 'clear_rcon' => 'sometimes|boolean']);
        if (isset($data['discord_webhook']) && $data['discord_webhook'] !== '') {
            abort_unless(Notifications::validWebhook($data['discord_webhook']), 422, 'Use a discord.com API webhook URL.');
        }
        $settings = Settings::forServer($server->id);
        $settings->options = $data['options'];
        if ($data['clear_discord'] ?? false) {
            $settings->discord_webhook = null;
        } elseif (isset($data['discord_webhook']) && $data['discord_webhook'] !== '') {
            $settings->discord_webhook = $data['discord_webhook'];
        }
        if ($data['clear_rcon'] ?? false) {
            $settings->rcon_password = null;
        } elseif (isset($data['rcon_password']) && $data['rcon_password'] !== '') {
            $settings->rcon_password = $data['rcon_password'];
        }
        $settings->save();

        return new JsonResponse($settings->publicData());
    }

    public function report(Request $r, Server $server, int $runId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'view');
        $run = Run::query()->where('server_id', $server->id)->findOrFail($runId);

        return new JsonResponse(Reports::export($run), 200, ['Content-Disposition' => 'attachment; filename="rust-wipe-'.$run->id.'.json"']);
    }

    public function restore(Request $r, Server $server, int $runId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'restore');
        $r->validate(['confirm' => 'required|accepted', 'request_uuid' => 'required|uuid']);
        $run = Run::query()->where('server_id', $server->id)->findOrFail($runId);
        abort_unless($run->backup_uuid && $run->original_environment !== null, 422, 'This run has no recoverable pre-wipe backup.');
        $restore = DB::transaction(function () use ($server, $run, $r): Restoration {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            Maintenance::guard($server->id);
            $existing = Restoration::query()->where('server_id', $server->id)->where('request_uuid', $r->input('request_uuid'))->first();
            if ($existing) {
                abort_if($existing->run_id !== $run->id, 409, 'This request ID belongs to a different restore.');

                return $existing;
            }
            abort_if(Run::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'running', 'deferred'])->exists() || Restoration::query()->where('server_id', $server->id)->whereIn('status', ['queued', 'stopping', 'restoring', 'restored'])->exists(), 409, 'A wipe or restore is already active.');

            return Restoration::query()->create(['server_id' => $server->id, 'request_uuid' => $r->input('request_uuid'), 'run_id' => $run->id, 'created_by' => $r->user()->id, 'backup_uuid' => $run->backup_uuid]);
        });
        RestoreWipe::dispatch($restore->id);

        return new JsonResponse($restore, 202);
    }

    public function recover(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        $r->validate(['confirm' => 'required|accepted']);
        abort_if(Run::query()->where('server_id', $server->id)->where('status', 'running')->where('started_at', '>', now('UTC')->subMinutes(25))->exists() || Restoration::query()->where('server_id', $server->id)->whereIn('status', ['stopping', 'restoring', 'restored'])->where('updated_at', '>', now('UTC')->subMinutes(25))->exists(), 409, 'An operation may still be running. Maintenance cannot be released yet.');
        abort_if($server->status === Server::STATUS_RESTORING_BACKUP, 409, 'Wings is still restoring a backup. Inspect its restore state first.');
        DB::table('ext_rust_server_wiper_maintenance')->where('server_id', $server->id)->delete();
        Restoration::query()->where('server_id', $server->id)->whereIn('status', ['stopping', 'restoring', 'restored'])->update(['status' => 'failed', 'message' => 'Expired maintenance recovered by an operator.', 'finished_at' => now('UTC')]);

        return new JsonResponse(null, 204);
    }

    public function updateMap(Request $r, Server $server, int $mapId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        $map = WipeMap::query()->where('server_id',$server->id)->findOrFail($mapId);
        $map->update($r->validate(['weight' => 'required|integer|min:1|max:1000', 'position' => 'required|integer|min:0|max:10000']));

        return new JsonResponse($map);
    }
}
