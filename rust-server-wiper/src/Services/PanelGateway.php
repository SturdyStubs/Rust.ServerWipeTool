<?php
declare(strict_types=1);
namespace RustServerWiper\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Pterodactyl\Contracts\Files\DeletesFiles;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Contracts\Servers\UpdatesServerVariable;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\Server;
use InvalidArgumentException;

final class PanelGateway implements Gateway
{
    private function server(int $id): Server { return Server::query()->findOrFail($id); }
    public function assertReady(int $serverId): void { $this->server($serverId)->validateCurrentState(); }
    public function environment(int $serverId): array
    {
        return $this->server($serverId)->variables->mapWithKeys(fn ($v) => [$v->env_variable => (string)($v->server_value ?? $v->default_value ?? '')])->all();
    }
    public function validateEnvironment(int $serverId, array $changes): void
    {
        $variables = $this->server($serverId)->egg->variables->keyBy('env_variable');
        foreach ($changes as $name => $value) {
            $variable = $variables->get($name);
            if (!$variable) throw new InvalidArgumentException("The egg has no $name variable. Clear that field or use a compatible Rust egg.");
            Validator::make(['value' => $value], ['value' => $variable->rules], [], ['value' => $name])->validate();
        }
    }
    public function updateEnvironment(int $serverId, array $changes): void
    {
        $this->validateEnvironment($serverId, $changes);
        $server = $this->server($serverId);
        $variables = $server->egg->variables->keyBy('env_variable');
        DB::transaction(function () use ($server, $changes, $variables): void {
            foreach ($changes as $name => $value) app(UpdatesServerVariable::class)->update($server, $variables->get($name)->id, (string)$value);
        });
    }
    public function list(int $serverId, string $directory): array { return Daemon::server($this->server($serverId))->files()->getDirectory($directory); }
    public function state(int $serverId): string { return Daemon::server($this->server($serverId))->details()['state'] ?? 'unknown'; }
    public function power(int $serverId, string $action): void { app(SendsServerPower::class)->send($this->server($serverId), $action); }
    public function commands(int $serverId, string $command): void { Daemon::server($this->server($serverId))->commands($command); }
    public function readOptional(int $serverId, string $path): ?string
    {
        try { return Daemon::server($this->server($serverId))->files()->getContent($path, 1024 * 1024); }
        catch (DaemonConnectionException $e) { if ($e->getStatusCode() === 404) return null; throw $e; }
    }
    public function write(int $serverId, string $path, string $content): void { Daemon::server($this->server($serverId))->files()->putContent($path, $content); }
    public function delete(int $serverId, string $root, array $names): void { app(DeletesFiles::class)->delete($this->server($serverId), $root, $names); }
    public function sync(int $serverId): void { Daemon::server($this->server($serverId))->sync(); }
    public function wait(): void { sleep(2); }
}
