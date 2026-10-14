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
        foreach ($groups as $root => $names) foreach ($names as $name) $files[] = "$root/$name";
        return ['files' => $files, 'count' => count($files), 'patterns' => Paths::patterns($payload)];
    }

    public function execute(int $serverId, array $payload, string $timezone, array $maps, callable $allowed, callable $progress): array
    {
        $this->gateway->assertReady($serverId);
        $environment = $this->gateway->environment($serverId);
        $changes = [];
        $now = new DateTimeImmutable('now', new DateTimeZone($timezone));
        foreach (['name' => 'HOSTNAME', 'description' => 'DESCRIPTION'] as $field => $variable) {
            if (($payload[$field] ?? null) !== null) $changes[$variable] = strtr($payload[$field], ['%DAY%' => $now->format('j'), '%MONTH%' => $now->format('n'), '%YEAR%' => $now->format('Y')]);
        }
        $level = $payload['level'] ?? null;
        if ($payload['random_level'] ?? false) {
            $choices = array_values(array_filter(array_unique($maps), fn ($url) => $url !== ($environment['MAP_URL'] ?? '')));
            if (!$choices) $choices = array_values(array_unique($maps));
            if (!$choices) throw new RuntimeException('Map rotation is enabled, but the map list is empty.');
            $level = $choices[random_int(0, count($choices) - 1)];
        }
        if ($level !== null) $changes['MAP_URL'] = $level;
        if (($payload['size'] ?? null) !== null) $changes['WORLD_SIZE'] = (string)$payload['size'];
        if ($payload['random_seed'] ?? false) $changes['WORLD_SEED'] = (string)random_int(1, 2147483647);
        elseif (($payload['seed'] ?? null) !== null) $changes['WORLD_SEED'] = (string)$payload['seed'];
        // An explicit procedural seed/size clears a previous custom map when the egg supports it.
        if ($level === null && !($payload['random_level'] ?? false) && (isset($changes['WORLD_SEED']) || isset($changes['WORLD_SIZE']))) {
            if (array_key_exists('MAP_URL', $environment)) $changes['MAP_URL'] = '';
        }
        $this->gateway->validateEnvironment($serverId, $changes);
        $patterns = Paths::patterns($payload);
        // Validate accessibility and unsafe paths before stopping the server.
        Paths::resolve($patterns, fn ($root) => $this->gateway->list($serverId, $root));
        $configPath = Paths::normalize($payload['identity_path'], false).'/cfg/server.cfg';
        if (isset($changes['WORLD_SEED'])) Paths::resolve([$configPath], fn ($root) => $this->gateway->list($serverId, $root));
        if (!$allowed()) throw new RuntimeException('The extension or schedule was disabled before execution.');
        $progress('Stopping the server.');
        if (!in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) $this->gateway->power($serverId, 'stop');
        $offline = false;
        $deadline = hrtime(true) + 180_000_000_000;
        for ($attempt = 0; $attempt < 90 && hrtime(true) < $deadline; $attempt++) {
            if (in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) { $offline = true; break; }
            $this->gateway->wait();
        }
        if (!$offline) throw new RuntimeException('Server did not stop within 180 seconds. No files were deleted.');
        if (!$allowed()) throw new RuntimeException('The extension or schedule was disabled. No files were deleted.');
        $this->gateway->assertReady($serverId);
        $groups = Paths::resolve($patterns, fn ($root) => $this->gateway->list($serverId, $root));
        $progress('Updating startup values and deleting selected files.');
        // A config-file seed override must agree with the new egg seed; only modify the exact directive.
        if (isset($changes['WORLD_SEED'])) {
            Paths::resolve([$configPath], fn ($root) => $this->gateway->list($serverId, $root));
            $config = $this->gateway->readOptional($serverId, $configPath);
            if ($config !== null) {
                $updated = preg_replace('/^(\h*server\.seed\h+)[^\r\n]*/m', '${1}'.$changes['WORLD_SEED'], $config);
                if ($updated !== $config) $this->gateway->write($serverId, $configPath, $updated);
            }
        }
        $this->gateway->updateEnvironment($serverId, $changes);
        $progress('Startup values updated.', ['deleted' => [], 'environment' => $changes]);
        $deleted = [];
        foreach ($groups as $root => $names) {
            // Refuse a concurrent manual start before each destructive batch.
            if (!in_array($this->gateway->state($serverId), ['offline', 'stopped'], true)) throw new RuntimeException('Server was started during the wipe. Execution stopped.');
            $this->gateway->delete($serverId, $root, $names);
            foreach ($names as $name) $deleted[] = "$root/$name";
            $progress('Deleted '.count($deleted).' entries.', ['deleted' => $deleted, 'environment' => $changes]);
        }
        $this->gateway->sync($serverId);
        $this->gateway->power($serverId, 'start');
        return ['deleted' => $deleted, 'environment' => $changes];
    }
}
