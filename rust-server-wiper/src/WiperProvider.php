<?php

declare(strict_types=1);

namespace RustServerWiper;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Contracts\Backups\RestoresBackupStatuses;
use Pterodactyl\Extensions\ExtensionProvider;
use RustServerWiper\Actions\RestoreResult;
use RustServerWiper\Console\ImportLegacyCommand;
use RustServerWiper\Console\TickCommand;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\PanelGateway;

final class WiperProvider extends ExtensionProvider
{
    public function register(): void
    {
        $this->app->bind(Gateway::class, PanelGateway::class);
    }

    public function boot(): void
    {
        $this->registerApiRoutes();
        $this->loadExtensionMigrations();
        $this->registerPermissions('Manage Rust wipe schedules and execute destructive wipes.', [
            'manage' => 'Legacy access: view, schedules and wipe execution. Does not grant restore or secret configuration.',
            'view' => 'Read schedules, calendar, presets, maintenance and wipe reports.',
            'schedule' => 'Manage wipe schedules, presets and map rotation.',
            'execute' => 'Preview files and execute an immediate destructive wipe.',
            'restore' => 'Restore an extension backup, replacing all current server files.',
            'configure' => 'Configure encrypted RCON and Discord credentials and recover expired maintenance.',
        ]);
        foreach (['Files/WritesFileContents', 'Files/DeletesFiles', 'Files/RenamesFiles', 'Files/CopiesFiles', 'Files/CreatesDirectories', 'Files/DecompressesFiles', 'Files/PullsFiles', 'Files/ChangesFilePermissions', 'Files/CompressesFiles', 'Servers/SendsServerPower', 'Servers/SendsServerCommands', 'Servers/UpdatesServerVariable', 'Servers/UpdatesServerStartup', 'Servers/ChangesServerEgg', 'Servers/ReinstallsServers', 'Servers/AttachesMountsToServers', 'Servers/DetachesMountsFromServers', 'Backups/RestoresBackups'] as $name) {
            $contract = 'Pterodactyl\\Contracts\\'.str_replace('/', '\\', $name);
            $wrapper = 'RustServerWiper\\Actions\\Guard'.basename($name);
            $this->wrapAction($contract, fn ($inner) => new $wrapper($inner));
        }
        $this->wrapAction(RestoresBackupStatuses::class, fn ($inner) => new RestoreResult($inner));
        $this->registerCommands([TickCommand::class, ImportLegacyCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('rust-server-wiper:tick')->everyMinute()->withoutOverlapping(5);
        });
    }
}
