<?php

declare(strict_types=1);

namespace RustServerWiper\Console;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Server;
use RustServerWiper\Http\WipeInput;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use Throwable;

final class ImportLegacyCommand extends Command
{
    protected $signature = 'rust-server-wiper:import-legacy {--dry-run : Validate and report without writing}';

    protected $description = 'Copy legacy addon schedules and maps into extension tables; imported schedules are paused.';

    public function handle(): int
    {
        if (! Schema::hasTable('wipes')) {
            $this->error('Legacy wipes table not found.');

            return 1;
        }
        $dry = (bool) $this->option('dry-run');
        $errors = 0;
        foreach (DB::table('wipes')->orderBy('id')->get() as $old) {
            if (Wipe::query()->where('legacy_id', $old->id)->exists()) {
                continue;
            }
            $server = Server::query()->find($old->server_id);
            if (! $server) {
                continue;
            }
            $repeat = json_decode($old->repeat ?? '[]', true);
            // Pre-5.1 recurrence encoding cannot be guessed safely.
            if (! is_array($repeat)) {
                $this->warn("Skipped wipe $old->id: unsupported repeat encoding.");
                $errors++;

                continue;
            }
            if (! $repeat && (! $old->time || ! empty($old->ran_at))) {
                $this->line("Skipped completed/unscheduled wipe $old->id.");

                continue;
            }
            $commands = Schema::hasTable('wipe_commands') ? DB::table('wipe_commands')->where('wipe_id', $old->id)->get()->map(fn ($c) => ['minutes' => (int) $c->time, 'command' => $c->command])->all() : [];
            $input = [
                'label' => 'Imported wipe '.$old->id, 'timezone' => $server->getAttribute('timezone') ?: 'UTC', 'enabled' => false,
                'repeat' => $repeat, 'time' => $repeat ? null : substr(str_replace(' ', 'T', $old->time), 0, 16),
                'payload' => ['name' => $old->name, 'description' => $old->description, 'size' => $old->size, 'seed' => $old->seed,
                    'random_seed' => (bool) $old->random_seed, 'random_level' => (bool) ($old->random_level ?? false), 'level' => $old->level ?: null,
                    'files' => $old->files, 'blueprints' => (bool) $old->blueprints, 'identity_path' => '/server/rust', 'commands' => $commands],
            ];
            // Legacy UI sometimes retained disabled field values; discard only contradictory unused values.
            if ($input['payload']['random_level'] || $input['payload']['level']) {
                $input['payload']['size'] = null;
                $input['payload']['seed'] = null;
                $input['payload']['random_seed'] = false;
            } elseif ($input['payload']['random_seed']) {
                $input['payload']['seed'] = null;
            }
            try {
                $request = Request::create('/', 'POST', $input);
                $data = WipeInput::parse($request);
                if (! $dry) {
                    Wipe::query()->create(['server_id' => $server->id, 'legacy_id' => $old->id, ...$data]);
                }
                $this->info(($dry ? 'Valid' : 'Imported paused')." wipe $old->id.");
            } catch (Throwable $e) {
                $this->warn("Skipped wipe $old->id: ".$e->getMessage());
                $errors++;
            }
        }
        if (Schema::hasTable('wipe_maps')) {
            foreach (DB::table('wipe_maps')->get() as $map) {
                if (! Server::query()->whereKey($map->server_id)->exists() || WipeMap::query()->where('legacy_id', $map->id)->exists()) {
                    continue;
                }
                if (! filter_var($map->map, FILTER_VALIDATE_URL) || ! in_array(parse_url($map->map, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    $this->warn("Skipped invalid map $map->id.");
                    $errors++;

                    continue;
                }
                if (! $dry) {
                    WipeMap::query()->create(['legacy_id' => $map->id, 'server_id' => $map->server_id, 'name' => $map->name, 'url' => $map->map]);
                }
                $this->info(($dry ? 'Valid' : 'Imported')." map $map->id.");
            }
        }
        $this->line('Imported schedules need review and activation. Legacy tables were left intact.');

        return $errors ? 1 : 0;
    }
}
