<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Pterodactyl\Models\Server;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Paths;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\Recurrence;

final class ConfigurationController
{
    private const FORMAT = 'rust-server-wiper.config';

    private function publicUrl(?string $url): ?string
    {
        if (! $url) {
            return $url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    private function exportPayload(array $payload): array
    {
        $payload['commands'] = [];
        $payload['level'] = $this->publicUrl($payload['level'] ?? null);

        return $payload;
    }

    public function export(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        $schedules = Wipe::query()->where('server_id', $server->id)->get()->map(fn ($wipe) => [
            'label' => $wipe->label, 'timezone' => $wipe->timezone, 'repeat' => $wipe->repeat,
            'time' => ! $wipe->repeat && $wipe->next_run_at ? $wipe->next_run_at->setTimezone($wipe->timezone)->format('Y-m-d\TH:i') : '',
            'payload' => $this->exportPayload($wipe->payload),
        ])->all();
        $presets = Preset::query()->where('server_id', $server->id)->get()->map(fn ($preset) => ['name' => $preset->name, 'payload' => $this->exportPayload($preset->payload)])->all();
        $maps = WipeMap::query()->where('server_id', $server->id)->get()->map(fn ($map) => ['name' => $map->name, 'url' => $this->publicUrl($map->url), 'weight' => $map->weight, 'position' => $map->position])->all();

        return new JsonResponse(['format' => self::FORMAT, 'version' => 1, 'exported_at' => now('UTC')->toAtomString(), 'schedules' => $schedules, 'presets' => $presets, 'maps' => $maps,
            'warnings' => ['Discord and RCON settings, console commands, URL credentials, query strings, and fragments are excluded. Review free-text fields before sharing. Imported schedules are paused.']],
            200, ['Content-Disposition' => 'attachment; filename="rust-wiper-configuration.json"']);
    }

    private function plan(Request $r): array
    {
        $data = $r->validate([
            'configuration' => 'required|array:format,version,exported_at,warnings,schedules,presets,maps',
            'configuration.format' => 'required|in:'.self::FORMAT, 'configuration.version' => 'required|integer|in:1',
            'configuration.exported_at' => 'sometimes|string|max:100', 'configuration.warnings' => 'sometimes|array|max:20', 'configuration.warnings.*' => 'string|max:2000',
            'configuration.schedules' => 'present|array|max:100', 'configuration.schedules.*' => 'array:label,timezone,time,repeat,payload',
            'configuration.presets' => 'present|array|max:100', 'configuration.presets.*' => 'array:name,payload',
            'configuration.presets.*.name' => 'required|string|max:191', 'configuration.presets.*.payload' => 'required|array',
            'configuration.maps' => 'present|array|max:100', 'configuration.maps.*' => 'array:name,url,weight,position',
            'configuration.maps.*.name' => 'required|string|max:191', 'configuration.maps.*.url' => 'required|url:http,https|max:2000',
            'configuration.maps.*.weight' => 'required|integer|min:1|max:1000', 'configuration.maps.*.position' => 'required|integer|min:0|max:10000',
            'target_identity' => 'required|string|max:1000',
        ]);
        abort_if(strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 2 * 1024 * 1024, 422, 'Configuration must be smaller than 2 MB.');
        $identity = Paths::normalize($data['target_identity'], false);
        $warnings = ['All imported schedules are paused. Existing schedules, maps, presets, and connection settings are preserved. Review paths and run a health check before enabling schedules.'];
        $adapt = function (array $payload) use ($identity, &$warnings): array {
            $old = Paths::normalize($payload['identity_path'], false);
            $files = [];
            foreach (preg_split('/\r\n|\n|\r/', $payload['files'] ?? '') ?: [] as $path) {
                $path = trim($path);
                if ($path === '') {
                    continue;
                }
                if ($path === $old || str_starts_with($path, $old.'/')) {
                    $path = $identity.substr($path, strlen($old));
                } else {
                    $warnings[] = 'A path outside the source identity directory is unchanged: '.$path;
                }
                $files[] = $path;
            }
            $payload['identity_path'] = $identity;
            $payload['files'] = implode("\n", $files);
            abort_if(strlen($payload['files']) > 20000, 422, 'Adapted file patterns exceed 20,000 bytes. Shorten the target directory or the import.');
            Paths::patterns($payload);

            return $payload;
        };
        $schedules = [];
        foreach ($data['configuration']['schedules'] as $draft) {
            $parsed = WipeInput::parse(Request::create('/', 'POST', [...$draft, 'enabled' => false]), true);
            $parsed['payload'] = $adapt($parsed['payload']);
            if ($parsed['repeat']) {
                $parsed['next_run_at'] = Recurrence::next($parsed['repeat'], $parsed['timezone'], now('UTC')->toDateTimeImmutable());
            } else {
                $date = ! empty($draft['time']) ? Recurrence::localTime($draft['time'], $parsed['timezone']) : null;
                $parsed['next_run_at'] = $date && $date > now('UTC')->toDateTimeImmutable() ? $date : null;
                if (! $parsed['next_run_at']) {
                    $warnings[] = $parsed['label'].': choose a future date before enabling this one-time schedule.';
                }
            }
            $parsed['next_run_at'] = $parsed['next_run_at']?->format(DATE_ATOM);
            $schedules[] = $parsed;
        }
        $presets = [];
        foreach ($data['configuration']['presets'] as $preset) {
            $parsed = WipeInput::parse(Request::create('/', 'POST', ['label' => $preset['name'], 'timezone' => 'UTC', 'enabled' => false, 'repeat' => [], 'payload' => $preset['payload']]), true);
            $presets[] = ['name' => $preset['name'], 'payload' => $adapt($parsed['payload'])];
        }
        abort_unless(count($schedules) + count($presets) + count($data['configuration']['maps']) > 0, 422, 'The configuration is empty.');

        return ['schedules' => $schedules, 'presets' => $presets, 'maps' => $data['configuration']['maps'], 'warnings' => array_values(array_unique($warnings)), 'target_identity' => $identity];
    }

    public function preview(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');

        return new JsonResponse($this->plan($r));
    }

    public function import(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'schedule');
        $r->validate(['confirm' => 'required|accepted', 'request_uuid' => 'required|uuid']);
        // Stable across midnight/time changes: hash the submitted content, not computed next-run dates.
        $hash = hash('sha256', json_encode([$r->input('configuration'), $r->input('target_identity')], JSON_THROW_ON_ERROR));
        $summary = DB::transaction(function () use ($r, $server, $hash) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $existing = DB::table('ext_rust_server_wiper_imports')->where('server_id', $server->id)->where('request_uuid', $r->input('request_uuid'))->first();
            if ($existing) {
                abort_if($existing->content_hash !== $hash, 409, 'This import request ID was already used with different content.');

                return json_decode($existing->summary, true, flags: JSON_THROW_ON_ERROR);
            }
            $plan = $this->plan($r);
            foreach (['schedules' => Wipe::class, 'presets' => Preset::class, 'maps' => WipeMap::class] as $key => $model) {
                abort_if($model::query()->where('server_id', $server->id)->count() + count($plan[$key]) > 100, 422, 'Import would exceed the 100 '.$key.' limit.');
                foreach ($plan[$key] as $record) {
                    $model::query()->create(['server_id' => $server->id, ...$record, ...($key === 'schedules' ? ['enabled' => false, 'created_by' => $r->user()->id, 'updated_by' => $r->user()->id] : [])]);
                }
            }
            $result = ['schedules' => count($plan['schedules']), 'presets' => count($plan['presets']), 'maps' => count($plan['maps']), 'warnings' => $plan['warnings']];
            DB::table('ext_rust_server_wiper_imports')->insert(['server_id' => $server->id, 'request_uuid' => $r->input('request_uuid'), 'content_hash' => $hash, 'summary' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);

            return $result;
        });

        return new JsonResponse($summary, 201);
    }
}
