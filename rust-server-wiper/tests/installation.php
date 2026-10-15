<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Models\Extension;
use Pterodactyl\Services\Extensions\ExtensionConsoleRegistry;
use Pterodactyl\Services\Extensions\ExtensionProviderLoader;
use Pterodactyl\Services\Extensions\ExtensionRepository;

// Run only against a disposable panel checkout. The isolated SQLite database is in memory.
$panel = realpath($argv[1] ?? dirname(__DIR__, 2).'/.reference-panel');
$package = realpath($argv[2] ?? dirname(__DIR__, 2).'/rust-server-wiper-2.0.0.pteroext');
if (! $panel || ! $package) {
    fwrite(STDERR, "Usage: php tests/installation.php /path/to/disposable/panel /path/to/package.pteroext\n");
    exit(1);
}
require $panel.'/vendor/autoload.php';
$directory = $panel.'/storage/framework/testing/wiper-'.bin2hex(random_bytes(4));
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'PTERODACTYL_EXTENSIONS_ENABLED' => 'true', 'PTERODACTYL_EXTENSIONS_DIRECTORY' => $directory] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
$app = require $panel.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (['servers', 'users', 'nodes', 'eggs', 'locations', 'mounts', 'database_hosts'] as $table) {
    Schema::create($table, function (Blueprint $t) {
        $t->increments('id');
    });
}
foreach (glob($panel.'/database/migrations/*.php') as $file) {
    if (preg_match('/(create_extensions_tables|add_scope_and_secrets_to_extension_settings|add_resource_scopes_to_extension_settings|add_error_phase_to_extensions)/', $file)) {
        (require $file)->up();
    }
}
Artisan::call('migrate:install');
$code = Artisan::call('p:extension:install', ['path' => $package, '--enable' => true, '--no-interaction' => true]);
echo Artisan::output();
if ($code !== 0) {
    exit($code);
}
$repository = $app->make(ExtensionRepository::class);
$repository->flushDiscovery();
$app->make(ExtensionProviderLoader::class)->registerProviders($repository->enabled());
$record = Extension::query()->where('identifier', 'rust-server-wiper')->firstOrFail();
if (! $record->enabled || $record->error) {
    throw new RuntimeException('Extension did not load: '.$record->error);
}
$routes = array_filter(iterator_to_array($app['router']->getRoutes()), fn ($r) => str_contains($r->uri(), 'extensions/rust-server-wiper'));
if (count($routes) !== 34) {
    throw new RuntimeException('Expected 34 extension routes, got '.count($routes));
}
$registry = $app->make(ExtensionConsoleRegistry::class)->snapshot();
if (count($registry['commands']['rust-server-wiper'] ?? []) !== 2 || count($registry['schedules']['rust-server-wiper'] ?? []) !== 1) {
    throw new RuntimeException('Commands or schedule not registered.');
}
if (! Schema::hasTable('ext_rust_server_wiper_runs')) {
    throw new RuntimeException('Extension migrations did not run.');
}
echo "PASS real package installation, enabling, migration, provider, routes, commands and schedule.\n";
