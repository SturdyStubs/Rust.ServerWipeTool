<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Models\Server;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Identity;
use RustServerWiper\Services\Recurrence;
use RustServerWiper\Services\Reports;
use RustServerWiper\Services\WipeEngine;
use Throwable;

final class AdminController
{
    public function __construct(private Gateway $gateway) {}

    private function authorize(Request $r): void
    {
        abort_unless($r->user()->root_admin, 403);
    }

    public function index(Request $r): JsonResponse
    {
        $this->authorize($r);
        $data = $r->validate(['page' => 'sometimes|integer|min:1']);
        $servers = Server::query()->orderBy('id')->paginate(100, ['*'], 'page', $data['page'] ?? 1);
        $rows = [];
        foreach ($servers as $server) {
            $env = $this->gateway->environment($server->id);
            if (! isset($env['WORLD_SEED'],$env['WORLD_SIZE'])) {
                continue;
            }
            $rows[] = ['id' => $server->id, 'name' => $server->name, 'identity' => Identity::detect($server->startup ?? '', $env)];
        }

        return new JsonResponse(['servers' => $rows, 'has_more' => $servers->hasMorePages(), 'page' => $servers->currentPage(),
            'presets' => Preset::query()->orderBy('name')->limit(500)->get(),
            'runs' => Run::query()->latest('id')->limit(100)->get()->map(fn ($r) => Reports::export($r))]);
    }

    private function plan(Request $r): array
    {
        $this->authorize($r);
        $input = $r->validate(['preset_id' => 'required|integer', 'server_ids' => 'required|array|min:1|max:50', 'server_ids.*' => 'required|integer|distinct|exists:servers,id',
            'time' => 'required|date_format:Y-m-d\TH:i', 'timezone' => 'required|timezone:all', 'stagger_minutes' => 'required|integer|min:0|max:1440', 'use_preset_identity' => 'required|boolean']);
        $preset = Preset::query()->findOrFail($input['preset_id']);
        try {
            $start = Recurrence::localTime($input['time'], $input['timezone']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['time' => $e->getMessage()]);
        }
        $rows = [];
        foreach ($input['server_ids'] as $i => $id) {
            try {
                $server = Server::query()->findOrFail($id);
                $env = $this->gateway->environment($id);
                if (! isset($env['WORLD_SEED'],$env['WORLD_SIZE'])) {
                    throw new \RuntimeException('Not a compatible Rust egg.');
                }
                $identity = Identity::detect($server->startup ?? '', $env);
                $payload = $preset->payload;
                if ($identity['path']) {
                    $source = $payload['identity_path'];
                    $target = $identity['path'];
                    $payload['files'] = implode("\n", array_map(fn ($p) => str_starts_with(trim($p), $source.'/') ? $target.substr(trim($p), strlen($source)) : trim($p), preg_split('/\r\n|\r|\n/', $payload['files'] ?? '') ?: []));
                    $payload['identity_path'] = $target;
                } elseif (! $input['use_preset_identity']) {
                    throw new \RuntimeException('Identity unknown; review the preset directory or enable the explicit fallback.');
                }
                $time = $start->modify('+'.($i * $input['stagger_minutes']).' minutes');
                $draft = ['label' => mb_substr($preset->name.' · '.$server->name, 0, 191), 'timezone' => $input['timezone'], 'enabled' => true, 'time' => $time->setTimezone(new \DateTimeZone($input['timezone']))->format('Y-m-d\TH:i'), 'repeat' => [], 'payload' => $payload];
                $data = WipeInput::parse(Request::create('/', 'POST', $draft));
                $data['next_run_at'] = $data['next_run_at']->format(DATE_ATOM);
                $this->gateway->assertReady($id);
                $preview = (new WipeEngine($this->gateway))->preview($id, $payload);
                $rows[] = ['server_id' => $id, 'name' => $server->name, 'data' => $data, 'files' => $preview['files'], 'count' => $preview['count'], 'error' => null];
            } catch (Throwable $e) {
                $rows[] = ['server_id' => $id, 'error' => $e->getMessage()];
            }
        }

        return $rows;
    }

    public function preview(Request $r): JsonResponse
    {
        return new JsonResponse(['servers' => $this->plan($r)]);
    }

    public function store(Request $r): JsonResponse
    {
        $r->validate(['confirm' => 'required|accepted', 'batch_uuid' => 'required|uuid']);
        $plan = $this->plan($r);
        $results = [];
        foreach ($plan as $row) {
            if ($row['error']) {
                $results[] = $row;

                continue;
            }
            try {
                $wipe = DB::transaction(function () use ($r, $row): Wipe {
                    Server::query()->whereKey($row['server_id'])->lockForUpdate()->firstOrFail();
                    if (Wipe::query()->where('server_id', $row['server_id'])->count() >= 100) {
                        throw new \RuntimeException('Maximum 100 schedules per server.');
                    }

                    return Wipe::query()->firstOrCreate(['server_id' => $row['server_id'], 'batch_uuid' => $r->input('batch_uuid')], ['created_by' => $r->user()->id, 'updated_by' => $r->user()->id, ...$row['data']]);
                });
                $results[] = ['server_id' => $row['server_id'], 'wipe_id' => $wipe->id, 'next_run_at' => $wipe->next_run_at, 'error' => null];
            } catch (Throwable $e) {
                $results[] = ['server_id' => $row['server_id'], 'error' => $e->getMessage()];
            }
        }

        return new JsonResponse(['servers' => $results],201);
    }
}
