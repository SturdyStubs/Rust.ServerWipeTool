<?php
declare(strict_types=1);

namespace RustServerWiper;

use Illuminate\Console\Scheduling\Schedule;
use Pterodactyl\Extensions\ExtensionProvider;
use RustServerWiper\Console\TickCommand;
use RustServerWiper\Console\ImportLegacyCommand;
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
            'manage' => 'Manage schedules, map rotation, startup values, selected files and wipe execution.',
        ]);
        $this->registerCommands([TickCommand::class, ImportLegacyCommand::class]);
        $this->registerSchedule(function (Schedule $schedule): void {
            $schedule->command('rust-server-wiper:tick')->everyMinute()->withoutOverlapping(5);
        });
    }
}
