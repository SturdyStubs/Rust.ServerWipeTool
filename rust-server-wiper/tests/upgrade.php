<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
// Each upgrade phase gets a fresh PHP process, matching panel request/worker reloads.
if (($argv[1] ?? '') !== '--stage') {
    $panel = realpath($argv[1] ?? dirname(__DIR__, 2).'/.reference-panel');
    $old = realpath($argv[2] ?? dirname(__DIR__, 2).'/rust-server-wiper-2.0.0.pteroext');
    $new = realpath($argv[3] ?? dirname(__DIR__, 2).'/rust-server-wiper-2.1.0-beta.1.pteroext');
    if (! $panel || ! $old || ! $new) {
        fwrite(STDERR, "Usage: php tests/upgrade.php /path/to/disposable/panel baseline.pteroext new.pteroext\n");
        exit(1);
    }
    $directory = $panel.'/storage/framework/testing/wiper-upgrade-'.bin2hex(random_bytes(4));
    mkdir($directory, 0777, true);
    touch($directory.'/database.sqlite');
    foreach (['baseline', 'upgrade', 'verify'] as $stage) {
        $process = proc_open([PHP_BINARY, __FILE__, '--stage', $stage, $panel, $old, $new, $directory], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);
        if (! is_resource($process) || ($code = proc_close($process)) !== 0) {
            exit($code ?? 1);
        }
    }
    echo "PASS baseline install, data-preserving upgrade, fresh provider boot, disable and re-enable.\n";
    exit(0);
}
[$script,$flag,$stage,$panel,$old,$new,$directory] = $argv;
require $panel.'/vendor/autoload.php';
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'PTERODACTYL_EXTENSIONS_ENABLED' => 'true', 'PTERODACTYL_EXTENSIONS_DIRECTORY' => $directory.'/extensions'] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require $panel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($stage === 'baseline') {
    foreach (['servers', 'users', 'nodes', 'eggs', 'locations', 'mounts', 'database_hosts'] as $table) {
        Schema::create($table, fn (Blueprint $t) => $t->increments('id'));
    }
    foreach (glob($panel.'/database/migrations/*.php') as $file) {
        if (preg_match('/(create_extensions_tables|add_scope_and_secrets_to_extension_settings|add_resource_scopes_to_extension_settings|add_error_phase_to_extensions)/', $file)) {
            (require $file)->up();
        }
    }
    Artisan::call('migrate:install');
    if (Artisan::call('p:extension:install', ['path' => $old, '--enable' => true, '--no-interaction' => true])) {
        throw new RuntimeException(Artisan::output());
    }
    DB::table('servers')->insert(['id' => 1]);
    DB::table('ext_rust_server_wiper_wipes')->insert(['server_id' => 1, 'label' => 'Preserved schedule', 'timezone' => 'UTC', 'payload' => '{"files":"/server/rust/*.sav"}', 'repeat' => '["1 4 14:00"]', 'next_run_at' => '2030-01-03 14:00:00', 'enabled' => false]);
    DB::table('ext_rust_server_wiper_maps')->insert(['server_id' => 1, 'name' => 'Preserved map', 'url' => 'https://example.com/a.map']);
    DB::table('ext_rust_server_wiper_runs')->insert(['server_id' => 1, 'label' => 'Preserved history', 'payload' => '{}', 'timezone' => 'UTC', 'due_at' => '2026-01-01 00:00:00', 'status' => 'completed']);
} elseif ($stage === 'upgrade') {
    if (Artisan::call('p:extension:install', ['path' => $new, '--replace' => true, '--no-interaction' => true])) {
        throw new RuntimeException(Artisan::output());
    }
} else {
    $record = DB::table('extensions')->where('identifier', 'rust-server-wiper')->first();
    if ($record->version !== '2.1.0-beta.1' || ! $record->enabled || $record->error) {
        throw new RuntimeException('Upgrade failed to load the new provider: '.($record->error ?? 'version/state mismatch'));
    }
    foreach (['wipes' => 'Preserved schedule', 'maps' => 'Preserved map', 'runs' => 'Preserved history'] as $table => $value) {
        $column = $table === 'maps' ? 'name' : 'label';
        if (! DB::table('ext_rust_server_wiper_'.$table)->where($column, $value)->exists()) {
            throw new RuntimeException('Baseline '.$table.' data was lost.');
        }
    }
    if (! Schema::hasTable('ext_rust_server_wiper_presets') || ! Schema::hasColumn('ext_rust_server_wiper_runs', 'backup_uuid')) {
        throw new RuntimeException('New migration did not run.');
    }
    if (Artisan::call('p:extension:disable', ['id' => 'rust-server-wiper', '--no-interaction' => true])) {
        throw new RuntimeException(Artisan::output());
    }
    if (Artisan::call('p:extension:enable', ['id' => 'rust-server-wiper', '--no-interaction' => true])) {
        throw new RuntimeException(Artisan::output());
    }
}
echo "PASS upgrade phase: $stage.\n";
