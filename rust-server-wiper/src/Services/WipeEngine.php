<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class WipeEngine
{
    public function __construct(private Gateway $gateway) {}

    public function preview(int $serverId, array $payload): array
    {
        $groups = Paths::resolve(Paths::patterns($payload), fn ($root) => $this->gateway->list($serverId, $root));
        $files = [];
        foreach ($groups as $root => $names) {
            foreach ($names as $name) {
                $files[] = "$root/$name";
            }
        }

        return ['files' => $files, 'count' => count($files), 'patterns' => Paths::patterns($payload)];
    }

    /** Read-only execution preflight, shared with the health check. */
    public function preflight(int $serverId, array $payload, string $timezone, array $maps, array $context = []): array
    {
        $this->gateway->assertReady($serverId);
        $environment = $this->gateway->environment($serverId);
        $changes = [];
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        foreach (['name' => 'HOSTNAME', 'description' => 'DESCRIPTION'] as $field => $variable) {
            if (($payload[$field] ?? null) !== null) {
                $changes[$variable] = strtr($payload[$field], ['%DAY%' => $now->format('j'), '%MONTH%' => $now->format('n'), '%YEAR%' => $now->format('Y')]);
            }
        }
        $level = $payload['level'] ?? null;
        if ($payload['random_level'] ?? false) {
            $selection = MapSelector::choose($maps, $payload['map_mode'] ?? 'random', $context['recent_maps'] ?? [], $environment['MAP_URL'] ?? '', $payload['exclude_recent'] ?? 0);
            $level = $selection['url'];
        }
        if ($level !== null && $level !== '') {
            $this->gateway->verifyMap($level);
        }
        if ($level !== null) {
            $changes['MAP_URL'] = $level;
        }
        if (($payload['size'] ?? null) !== null) {
            $changes['WORLD_SIZE'] = (string) $payload['size'];
        }
        if ($payload['random_seed'] ?? false) {
            $changes['WORLD_SEED'] = (string) random_int(1, 2147483647);
        } elseif (($payload['seed'] ?? null) !== null) {
            $changes['WORLD_SEED'] = (string) $payload['seed'];
        }
        // An explicit procedural seed/size clears a previous custom map when the egg supports it.
        if ($level === null && ! ($payload['random_level'] ?? false) && (isset($changes['WORLD_SEED']) || isset($changes['WORLD_SIZE']))) {
            if (array_key_exists('MAP_URL', $environment)) {
                $changes['MAP_URL'] = '';
            }
        }
        $this->gateway->validateEnvironment($serverId, $changes);
        $patterns = Paths::patterns($payload);
        // Validate accessibility and unsafe paths before stopping the server.
        Paths::resolve($patterns, fn ($root) => $this->gateway->list($serverId, $root));
        $configPath = Paths::normalize($payload['identity_path'], false).'/cfg/server.cfg';
        if (isset($changes['WORLD_SEED'])) {
            Paths::resolve([$configPath], fn ($root) => $this->gateway->list($serverId, $root));
        }

        return compact('environment', 'changes', 'patterns', 'configPath', 'level') + ['selection' => $selection ?? null];
    }

    public function execute(int $serverId, array $payload, string $timezone, array $maps, callable $allowed, callable $progress, array $context = []): array
    {
        ['environment' => $environment, 'changes' => $changes, 'patterns' => $patterns, 'configPath' => $configPath, 'level' => $level, 'selection' => $selection] = $this->preflight($serverId, $payload, $timezone, $maps, $context);
        if (! $allowed()) {
            throw new RuntimeException('The extension or schedule was disabled before execution.');
        }
        $progress('Stopping the server.', null, ['event' => 'wiping']);
        if (! in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) {
            $this->gateway->power($serverId, 'stop');
        }
        $offline = false;
        $deadline = hrtime(true) + 180_000_000_000;
        for ($attempt = 0; $attempt < 90 && hrtime(true) < $deadline; $attempt++) {
            if (in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) {
                $offline = true;
                break;
            }
            $this->gateway->wait();
        }
        if (! $offline) {
            throw new RuntimeException('Server did not stop within 180 seconds. No files were deleted.');
        }
        if (! $allowed()) {
            throw new RuntimeException('The extension or schedule was disabled. No files were deleted.');
        }
        $this->gateway->assertReady($serverId);
        $groups = Paths::resolve($patterns, fn ($root) => $this->gateway->list($serverId, $root));
        $original = array_intersect_key($environment, $changes);
        $metadata = ['original_environment' => $original, 'map_url' => $level, 'rotation_fallback' => $selection['fallback'] ?? false];
        $progress('Server stopped; preparing recovery information.', ['deleted' => [], 'environment' => $changes, 'map_url' => $level, 'rotation_fallback' => $selection['fallback'] ?? false], $metadata);
        if ($payload['backup'] ?? false) {
            $uuid = $this->gateway->backupStart($serverId, 'Rust wipe '.($context['run_id'] ?? '').' '.gmdate('Y-m-d H:i'));
            $progress('Waiting for the locked pre-wipe backup.', null, ['backup_uuid' => $uuid]);
            $seconds = $payload['backup_timeout'] ?? 300;
            $complete = false;
            $deadline = hrtime(true) + $seconds * 1_000_000_000;
            for ($attempt = 0; $attempt < (int) ceil($seconds / 2) && hrtime(true) < $deadline; $attempt++) {
                $status = $this->gateway->backupStatus($serverId, $uuid);
                if ($status === false) {
                    throw new RuntimeException('Pre-wipe backup failed. No files were deleted.');
                }
                if ($status === true) {
                    $complete = true;
                    break;
                }
                if (! $allowed()) {
                    throw new RuntimeException('Schedule or extension disabled while waiting for backup. No files were deleted.');
                }
                $this->gateway->wait();
            }
            if (! $complete) {
                throw new RuntimeException('Pre-wipe backup confirmation timed out. No files were deleted.');
            }
        }
        if (! $allowed()) {
            throw new RuntimeException('Schedule or extension disabled before deletion.');
        }
        $progress('Updating startup values and deleting selected files.');
        // A config-file seed override must agree with the new egg seed; only modify the exact directive.
        if (isset($changes['WORLD_SEED'])) {
            Paths::resolve([$configPath], fn ($root) => $this->gateway->list($serverId, $root));
            $config = $this->gateway->readOptional($serverId, $configPath);
            if ($config !== null) {
                $updated = preg_replace('/^(\h*server\.seed\h+)[^\r\n]*/m', '${1}'.$changes['WORLD_SEED'], $config);
                if ($updated !== $config) {
                    $this->gateway->write($serverId, $configPath, $updated);
                }
            }
        }
        $this->gateway->updateEnvironment($serverId, $changes);
        $result = ['deleted' => [], 'environment' => $changes, 'map_url' => $level, 'rotation_fallback' => $selection['fallback'] ?? false];
        $progress('Startup values updated.', $result);
        $deleted = [];
        foreach ($groups as $root => $names) {
            // Refuse a concurrent manual start before each destructive batch.
            if (! in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) {
                throw new RuntimeException('Server was started during the wipe. Execution stopped.');
            }
            $this->gateway->delete($serverId, $root, $names);
            foreach ($names as $name) {
                $deleted[] = "$root/$name";
            }
            $result['deleted'] = $deleted;
            $progress('Deleted '.count($deleted).' entries.', $result);
        }
        $this->gateway->sync($serverId);
        $this->gateway->power($serverId, 'start');
        $result['readiness'] = 'start_accepted';
        if (($payload['readiness'] ?? 'none') !== 'none' || ($context['observe_running'] ?? false)) {
            $progress('Start accepted; verifying server readiness.', $result);
            $deadline = hrtime(true) + 300_000_000_000;
            $ready = false;
            $announcedRunning = false;
            for ($attempt = 0; $attempt < 150 && hrtime(true) < $deadline; $attempt++) {
                try {
                    $running = $this->gateway->state($serverId) === 'running';
                    if ($running && ! $announcedRunning) {
                        $announcedRunning = true;
                        $progress('Server is running after the wipe.', $result, ['event' => 'wiped']);
                    }
                    $ready = $running && (($payload['readiness'] ?? 'none') !== 'rcon' || $this->gateway->playerCount($serverId) >= 0);
                } catch (\Throwable) {
                    $ready = false;
                }
                if ($ready) {
                    break;
                }
                $this->gateway->wait();
            }
            $result['readiness'] = $ready ? (($payload['readiness'] ?? 'none') === 'rcon' ? 'rust_ready' : 'process_running') : 'timed_out';
            $progress($ready ? 'Readiness check passed.' : 'Readiness timed out. Files were wiped; inspect server startup.', $result);
        }

        return $result;
    }
}
