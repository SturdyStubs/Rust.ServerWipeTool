<?php
declare(strict_types=1);

use RustServerWiper\Services\Gateway;

final class FakeGateway implements Gateway
{
    public array $events = [];
    public array $env = ['WORLD_SEED' => '123', 'WORLD_SIZE' => '3500', 'MAP_URL' => '', 'HOSTNAME' => 'Rust', 'DESCRIPTION' => ''];
    public array $directories = [
        '/' => [['name' => 'server', 'file' => false, 'symlink' => false]],
        '/server' => [['name' => 'rust', 'file' => false, 'symlink' => false]],
        '/server/rust' => [
            ['name' => 'world.map', 'file' => true, 'symlink' => false],
            ['name' => 'world.sav', 'file' => true, 'symlink' => false],
            ['name' => 'player.blueprints.5.db', 'file' => true, 'symlink' => false],
            ['name' => 'player.blueprints.5.db-wal', 'file' => true, 'symlink' => false],
            ['name' => 'backups.sav', 'file' => false, 'symlink' => false],
            ['name' => 'cfg', 'file' => false, 'symlink' => false],
        ],
        '/server/rust/cfg' => [['name' => 'server.cfg', 'file' => true, 'symlink' => false]],
    ];
    public string $powerState = 'running';
    public bool $stopWorks = true;
    public bool $ready = true;
    public bool $failDelete = false;
    public bool $failList = false;
    public bool $failSync = false;
    public ?string $config = "// server.seed 999\nserver.seed 123\nserver.seedextra 456\n";
    public function assertReady(int $serverId): void { if (!$this->ready) throw new RuntimeException('Server unavailable.'); }
    public function environment(int $serverId): array { return $this->env; }
    public function validateEnvironment(int $serverId, array $changes): void { foreach ($changes as $name => $v) if (!array_key_exists($name, $this->env)) throw new RuntimeException("Missing variable $name"); }
    public function updateEnvironment(int $serverId, array $changes): void { $this->events[] = ['environment', $changes]; $this->env = array_replace($this->env, $changes); }
    public function list(int $serverId, string $directory): array { if ($this->failList) throw new RuntimeException('Wings disconnected.'); return $this->directories[$directory] ?? []; }
    public function state(int $serverId): string { return $this->powerState; }
    public function power(int $serverId, string $action): void { $this->events[] = ['power', $action]; if ($action === 'stop' && $this->stopWorks) $this->powerState = 'offline'; if ($action === 'start') $this->powerState = 'running'; }
    public function commands(int $serverId, string $command): void { $this->events[] = ['command', $command]; }
    public function readOptional(int $serverId, string $path): ?string { return $this->config; }
    public function write(int $serverId, string $path, string $content): void { $this->events[] = ['write', $path]; $this->config = $content; }
    public function delete(int $serverId, string $root, array $names): void { if ($this->powerState !== 'offline') throw new RuntimeException('Deletion attempted while running.'); if ($this->failDelete) throw new RuntimeException('Delete failed.'); $this->events[] = ['delete', $root, $names]; }
    public function sync(int $serverId): void { if ($this->failSync) throw new RuntimeException('Sync failed.'); $this->events[] = ['sync']; }
    public function wait(): void {}
}
