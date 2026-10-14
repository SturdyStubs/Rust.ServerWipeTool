<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Contracts\Backups\RestoresBackupStatuses;
use Pterodactyl\Contracts\Servers\SendsServerPower;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use RustServerWiper\Actions\GuardSendsServerPower;
use RustServerWiper\Actions\RestoreResult;
use RustServerWiper\Http\AdminController;
use RustServerWiper\Http\FeaturesController;
use RustServerWiper\Http\WiperController;
use RustServerWiper\Jobs\RestoreWipe;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Services\Calendar;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Identity;
use RustServerWiper\Services\Maintenance;
use RustServerWiper\Services\MapSelector;
use RustServerWiper\Services\Notifications;
use RustServerWiper\Services\PanelGateway;
use RustServerWiper\Services\Permissions;
use RustServerWiper\Services\PublicUrl;
use RustServerWiper\Services\Recurrence;
use RustServerWiper\Services\Reports;
use RustServerWiper\Services\WebRcon;
use RustServerWiper\Services\WipeEngine;

test('identity expands a startup variable', fn () => eq(Identity::detect('./RustDedicated +server.identity {{IDENTITY}}', ['IDENTITY' => 'production'])['path'], '/server/production'));
test('quoted custom identity detected', fn () => eq(Identity::detect('./RustDedicated +server.identity "custom-rust"', [])['path'], '/server/custom-rust'));
test('unsafe or uncertain identity never guessed', function () {
    eq(Identity::detect('./start-wrapper', [])['path'], null);
    eq(Identity::detect('+server.identity ../other', [])['path'], null);
});
test('ordered rotation wraps by saved position', function () {
    eq(MapSelector::choose([['url' => 'a', 'position' => 0], ['url' => 'b', 'position' => 1], ['url' => 'c', 'position' => 2]], 'ordered', [], 'b', 0)['url'], 'c');
});
test('recent-map exclusion selects the remaining map', function () {
    eq(MapSelector::choose(['a', 'b', 'c'], 'weighted', ['b'], 'a', 1)['url'], 'c');
});
test('small rotation pool falls back safely', function () {
    $choice = MapSelector::choose(['a', 'b'], 'ordered', ['a', 'b'], 'a', 2);
    eq($choice['url'], 'b');
    eq($choice['fallback'], true);
});
test('backup failure prevents every destructive mutation', function () {
    $g = new FakeGateway;
    $g->backupResult = false;
    raises(fn () => execute($g, ['backup' => true]), 'backup failed');
    eq(hasEvent($g, 'delete'), false);
    eq(hasEvent($g, 'environment'), false);
    eq($g->powerState, 'offline');
});
test('unfinished backup never deletes', function () {
    $g = new FakeGateway;
    $g->backupResult = null;
    raises(fn () => execute($g, ['backup' => true, 'backup_timeout' => 30]), 'timed out');
    eq(hasEvent($g, 'delete'), false);
});
test('backup is confirmed before deletion and its UUID is recorded', function () {
    $g = new FakeGateway;
    $metadata = [];
    (new WipeEngine($g))->execute(1, array_replace(payload(), ['backup' => true]), 'UTC', [], fn () => true, function ($m, $r = null, $meta = []) use (&$metadata) {
        $metadata = array_replace($metadata, $meta);
    });
    $events = array_column($g->events, 0);
    eq(array_search('backup', $events) < array_search('delete', $events), true);
    eq($metadata['backup_uuid'], 'fake-backup-uuid');
});
test('bad map URL prevents server stop', function () {
    $g = new FakeGateway;
    $g->failMap = true;
    raises(fn () => execute($g, ['level' => 'https://example.com/a.map']), 'unavailable');
    eq($g->events, []);
});
test('Rust readiness requires a real RCON reply', function () {
    $g = new FakeGateway;
    $r = execute($g, ['readiness' => 'rcon']);
    eq($r['readiness'], 'rust_ready');
});
test('readiness timeout does not replay deletion', function () {
    $g = new FakeGateway;
    $g->unknownPlayers = true;
    $r = execute($g, ['readiness' => 'rcon']);
    eq($r['readiness'], 'timed_out');
    eq(count(array_filter($g->events, fn ($e) => $e[0] === 'delete')), 1);
    eq(count(array_filter($g->events, fn ($e) => $e[0] === 'power' && $e[1] === 'start')), 1);
});
test('local and metadata URLs are rejected', function () {
    raises(fn () => PublicUrl::addresses('http://127.0.0.1/test.map'), 'private');
    raises(fn () => PublicUrl::addresses('http://169.254.169.254/test.map'), 'private');
    raises(fn () => PublicUrl::addresses('http://[::1]/test.map'), 'private');
    raises(fn () => PublicUrl::addresses('https://user:pass@example.com/test.map'), 'credentials');
});
test('redirect to private address is rejected', function () {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'http://127.0.0.1/a.map'])]);
    raises(fn () => (new PublicUrl)->check('https://8.8.8.8/a.map'), 'private');
    Http::preventStrayRequests();
});
test('public map URL accepts an HTTP success', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 200)]);
    (new PublicUrl)->check('https://8.8.8.8/a.map');
});
test('maintenance blocks a panel power action but allows internal work', function () {
    resetRuns();
    Maintenance::acquire(1, 'test');
    $inner = new class implements SendsServerPower
    {
        public int $calls = 0;

        public function send(Server $server, string $signal): void
        {
            $this->calls++;
        }
    };
    $guard = new GuardSendsServerPower($inner);
    $server = Server::query()->findOrFail(1);
    raises(fn () => $guard->send($server, 'start'), 'maintenance');
    Maintenance::internal(1, fn () => $guard->send($server, 'stop'));
    eq($inner->calls, 1);
    Maintenance::release(1, 'test');
    $guard->send($server, 'start');
    eq($inner->calls, 2);
});
test('maintenance bypass is cleared when an operation throws', function () {
    Maintenance::acquire(1, 'test');
    raises(fn () => Maintenance::internal(1, fn () => throw new RuntimeException('Test')));
    raises(fn () => Maintenance::guard(1), 'maintenance');
    Maintenance::release(1, 'test');
});
test('legacy manage cannot restore or configure secrets', function () {
    $user = new class
    {
        public function can($p, $s)
        {
            return $p === 'ext.rust-server-wiper.manage';
        }
    };
    $s = Server::query()->findOrFail(1);
    eq(Permissions::can($user, $s, 'execute'), true);
    eq(Permissions::can($user, $s, 'restore'), false);
    eq(Permissions::can($user, $s, 'configure'), false);
});
test('read-only grant cannot execute a wipe', function () {
    $user = new class
    {
        public function can($p, $s)
        {
            return $p === 'ext.rust-server-wiper.view';
        }
    };
    $s = Server::query()->findOrFail(1);
    eq(Permissions::can($user, $s, 'view'), true);
    eq(Permissions::can($user, $s, 'execute'), false);
});
test('specific schedule grant implies viewing only', function () {
    $user = new class
    {
        public function can($p, $s)
        {
            return $p === 'ext.rust-server-wiper.schedule';
        }
    };
    $s = Server::query()->findOrFail(1);
    eq(Permissions::can($user, $s, 'view'), true);
    eq(Permissions::can($user, $s, 'execute'), false);
});
test('server environment response never returns RCON password', function () {
    $g = new FakeGateway;
    $g->env['RCON_PASS'] = 'private-secret';
    $c = new WiperController($g, new WipeEngine($g));
    $data = $c->index(request(), Server::query()->findOrFail(1))->getData(true);
    eq(isset($data['environment']['RCON_PASS']), false);
});
test('settings secrets are encrypted and omitted from responses', function () {
    $s = Settings::forServer(1);
    $s->options = [];
    $s->discord_webhook = 'https://discord.com/api/webhooks/123/secret';
    $s->rcon_password = 'private-password';
    $s->save();
    $raw = DB::table('ext_rust_server_wiper_settings')->where('server_id', 1)->first();
    eq(str_contains($raw->rcon_password, 'private-password'), false);
    eq(isset($s->toArray()['rcon_password']), false);
    eq(Settings::forServer(1)->rcon_password, 'private-password');
});
test('blank secret input preserves saved values', function () {
    $c = new FeaturesController;
    $c->saveSettings(request(['options' => [], 'discord_webhook' => null, 'rcon_password' => null]), Server::query()->findOrFail(1));
    eq(Settings::forServer(1)->rcon_password, 'private-password');
});
test('Discord failures do not affect wipe state and are not duplicated', function () {
    $s = Settings::forServer(1);
    $s->options = ['discord_events' => ['failed']];
    $s->save();
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('', 500)]);
    $n = new Notifications;
    $n->send(1, 'failed', 'test:notify', ['label' => 'Test', 'message' => 'Failure']);
    $n->send(1, 'failed', 'test:notify', ['label' => 'Test']);
    eq(DB::table('ext_rust_server_wiper_notifications')->where('event_key', 'test:notify')->count(), 1);
    eq(DB::table('ext_rust_server_wiper_notifications')->where('event_key', 'test:notify')->value('status'), 'failed');
});
test('reports redact credentials and signed URL queries', function () {
    $r = Reports::redact(['password' => 'secret', 'environment' => ['MAP_URL' => 'https://example.com/a.map?token=secret'], 'webhook' => 'secret']);
    eq(isset($r['password']), false);
    eq($r['environment']['MAP_URL'], 'https://example.com/a.map?[redacted]');
    eq(isset($r['webhook']), false);
});
test('recovery environment remains encrypted and hidden', function () {
    resetRuns();
    $r = Run::query()->create(['server_id' => 1, 'label' => 'Backup', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now(), 'original_environment' => ['HOSTNAME' => 'before']]);
    eq(str_contains(DB::table('ext_rust_server_wiper_runs')->where('id', $r->id)->value('original_environment'), 'before'), false);
    eq(isset($r->toArray()['original_environment']), false);
});
test('calendar flags overlapping wipes and previews future occurrences', function () {
    resetRuns();
    schedule();
    schedule(['label' => 'Other']);
    $calendar = Calendar::forServer(1);
    eq(count($calendar['events']), 24);
    eq($calendar['events'][0]['conflict'], true);
});
test('players defer without stop or deletion', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->players = 3;
    schedule(['payload' => array_replace(payload(), ['wait_players' => true])]);
    tick($g);
    eq(Run::query()->first()->status, 'deferred');
    eq($g->events, []);
});
test('player deferral retries and eventually runs when empty', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->players = 3;
    schedule(['payload' => array_replace(payload(), ['wait_players' => true])]);
    tick($g);
    $g->players = 0;
    Carbon::setTestNow('2026-10-01 14:05 UTC');
    tick($g);
    eq(Run::query()->first()->status, 'completed');
});
test('deferral deadline cancels instead of wiping occupied server', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->players = 3;
    schedule(['payload' => array_replace(payload(), ['wait_players' => true, 'max_deferral' => 5])]);
    tick($g);
    Carbon::setTestNow('2026-10-01 14:05 UTC');
    tick($g);
    eq(Run::query()->first()->status, 'cancelled');
    eq(hasEvent($g, 'delete'), false);
});
test('unknown player count follows the explicit policy', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->unknownPlayers = true;
    schedule(['payload' => array_replace(payload(), ['wait_players' => true, 'unknown_players' => 'cancel'])]);
    tick($g);
    eq(Run::query()->first()->status, 'cancelled');
    eq(hasEvent($g, 'power'), false);
});
test('preset validation and server scoping', function () {
    $c = new FeaturesController;
    $response = $c->savePreset(request(['name' => 'Map', 'payload' => payload()]), Server::query()->findOrFail(1));
    $id = $response->getData(true)['id'];
    raises(fn () => $c->deletePreset(request(), Server::query()->findOrFail(2), $id));
    eq(Preset::query()->whereKey($id)->exists(), true);
    $c->updatePreset(request(['name' => 'Full', 'payload' => array_replace(payload(), ['blueprints' => true])]), Server::query()->findOrFail(1), $id);
    eq(Preset::query()->find($id)->payload['blueprints'], true);
});
test('upgrade migration reruns without dropping baseline data', function () {
    $before = Wipe::query()->count();
    (require dirname(__DIR__).'/database/migrations/2026_10_14_000000_add_operations_features.php')->up();
    eq(Wipe::query()->count(), $before);
});
test('actual WebRCON handshake handles ping and fragmented player reply', function () {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__.'/mock-rcon.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        throw new RuntimeException('Mock RCON process did not start.');
    }
    try {
        stream_set_timeout($pipes[1], 5);
        $address = trim(fgets($pipes[1]));
        $port = (int) substr($address, strrpos($address, ':') + 1);
        eq((new WebRcon)->players('127.0.0.1', $port, 'test-password'), 2);
    } finally {
        foreach ($pipes as $pipe) {
            fclose($pipe);
        }proc_terminate($process);
        proc_close($process);
    }
});
Schema::create('users', function (Blueprint $t) {
    $t->increments('id');
    $t->boolean('root_admin')->default(false);
});
DB::table('users')->insert(['id' => 1, 'root_admin' => true]);
Schema::table('servers', function (Blueprint $t) {
    $t->string('name')->nullable();
    $t->text('startup')->nullable();
    $t->string('status')->nullable();
});
DB::table('servers')->where('id', 1)->update(['name' => 'Rust One', 'startup' => './RustDedicated +server.identity rust']);
DB::table('servers')->where('id', 2)->update(['name' => 'Rust Two', 'startup' => './RustDedicated +server.identity second']);
function adminRequest(array $data = [], bool $admin = true): Request
{
    $r = Request::create('/', 'POST', $data);
    $r->setUserResolver(fn () => new class($admin)
    {
        public int $id = 1;

        public bool $root_admin;

        public function __construct($v)
        {
            $this->root_admin = $v;
        }

        public function can($p, $s)
        {
            return $this->root_admin;
        }
    });

    return $r;
}
test('restore job restores files and environment but never starts server', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->powerState = 'running';
    $r = Run::query()->create(['server_id' => 1, 'label' => 'Recovery', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now(), 'status' => 'completed', 'backup_uuid' => 'fake-backup-uuid', 'original_environment' => ['WORLD_SEED' => '321']]);
    $restore = Restoration::query()->create(['server_id' => 1, 'run_id' => $r->id, 'created_by' => 1, 'backup_uuid' => $r->backup_uuid]);
    (new RestoreWipe($restore->id))->handle($g);
    eq($restore->fresh()->status, 'completed');
    eq($g->env['WORLD_SEED'], '321');
    eq($g->powerState, 'offline');
    eq(hasEvent($g, 'restore'), true);
    eq(Maintenance::status(1), null);
});
test('restore refuses unconfirmed backup before stopping', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->backupResult = false;
    $r = Run::query()->create(['server_id' => 1, 'label' => 'Recovery', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now(), 'status' => 'completed', 'backup_uuid' => 'fake-backup-uuid', 'original_environment' => []]);
    $restore = Restoration::query()->create(['server_id' => 1, 'run_id' => $r->id, 'created_by' => 1, 'backup_uuid' => $r->backup_uuid]);
    (new RestoreWipe($restore->id))->handle($g);
    eq($restore->fresh()->status, 'failed');
    eq($g->events, []);
});
test('restore callback records success only for its own active restore', function () {
    resetRuns();
    $r = Run::query()->create(['server_id' => 1, 'label' => 'Recovery', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()]);
    $restore = Restoration::query()->create(['server_id' => 1, 'run_id' => $r->id, 'created_by' => 1, 'backup_uuid' => 'fake-uuid', 'status' => 'restoring']);
    $inner = new class implements RestoresBackupStatuses
    {
        public function restoreStatus(string $uuid, Node $node, bool $success): Backup
        {
            return (new Backup)->forceFill(['server_id' => 1]);
        }
    };
    (new RestoreResult($inner))->restoreStatus('fake-uuid', new Node, true);
    eq($restore->fresh()->status, 'restored');
});
test('bulk administration denies non-admin users', function () {
    $g = new FakeGateway;
    $c = new AdminController($g);
    raises(fn () => $c->index(adminRequest([], false)));
});
test('batch previews adapt identities and stagger UTC timestamps', function () {
    $g = new FakeGateway;
    $c = new AdminController($g);
    $p = Preset::query()->where('server_id', 1)->first();
    $input = ['preset_id' => $p->id, 'server_ids' => [1, 2], 'time' => '2035-01-01T12:00', 'timezone' => 'UTC', 'stagger_minutes' => 10, 'use_preset_identity' => false];
    $rows = $c->preview(adminRequest($input))->getData(true)['servers'];
    eq($rows[0]['error'], null);
    eq($rows[1]['data']['payload']['identity_path'], '/server/second');
    eq(strtotime($rows[1]['data']['next_run_at']) - strtotime($rows[0]['data']['next_run_at']), 600);
});
test('batch retries do not duplicate schedules', function () {
    resetRuns();
    $g = new FakeGateway;
    $c = new AdminController($g);
    $p = Preset::query()->where('server_id', 1)->first();
    $input = ['preset_id' => $p->id, 'server_ids' => [1, 2], 'time' => '2035-01-01T12:00', 'timezone' => 'UTC', 'stagger_minutes' => 10, 'use_preset_identity' => false, 'confirm' => true, 'batch_uuid' => '123e4567-e89b-12d3-a456-426614174000'];
    $c->store(adminRequest($input));
    $c->store(adminRequest($input));
    eq(Wipe::query()->count(), 2);
});
test('a failed bulk target does not prevent successful targets', function () {
    resetRuns();
    DB::table('servers')->where('id', 2)->update(['startup' => './wrapper']);
    $g = new FakeGateway;
    $c = new AdminController($g);
    $p = Preset::query()->where('server_id', 1)->first();
    $rows = $c->store(adminRequest(['preset_id' => $p->id, 'server_ids' => [1, 2], 'time' => '2035-01-01T12:00', 'timezone' => 'UTC', 'stagger_minutes' => 10, 'use_preset_identity' => false, 'confirm' => true, 'batch_uuid' => '223e4567-e89b-12d3-a456-426614174000']))->getData(true)['servers'];
    eq($rows[0]['error'], null);
    eq(is_string($rows[1]['error']), true);
    eq(Wipe::query()->count(), 1);
});
test('calendar explains missing fifth weekdays and daylight-saving gaps', function () {
    $missing = Recurrence::skips(['5 1 12:00'], 'UTC', new DateTimeImmutable('2026-02-01'));
    eq($missing[0]['local'], '2026-02');
    $dst = Recurrence::skips(['2 7 02:30'], 'America/New_York', new DateTimeImmutable('2026-03-01'));
    eq($dst[0]['local'], '2026-03-08 02:30');
});
test('native backup overrides pteroignore without excluding files', function () {
    $inner = new class implements InitiatesBackups
    {
        public array $ignored = [];

        public bool $locked = false;

        public bool $override = true;

        public function setIsLocked(bool $v): self
        {
            $this->locked = $v;

            return $this;
        }

        public function setIgnoredFiles(?array $v): self
        {
            $this->ignored = $v ?? [];

            return $this;
        }

        public function initiate(Server $s, ?string $n = null, bool $override = false): Backup
        {
            $this->override = $override;

            return (new Backup)->forceFill(['uuid' => 'test-full-backup']);
        }
    };
    app()->instance(InitiatesBackups::class, $inner);
    eq((new PanelGateway)->backupStart(1, 'Recovery'), 'test-full-backup');
    eq($inner->locked, true);
    eq($inner->override, false);
    eq(count($inner->ignored), 1);
    eq(str_starts_with($inner->ignored[0], '#'), true);
});
test('immediate wipe request retries cannot wipe twice', function () {
    resetRuns();
    $g = new FakeGateway;
    app()->instance(Gateway::class, $g);
    $c = new WiperController($g, new WipeEngine($g));
    $input = input(['confirm' => true, 'request_uuid' => '323e4567-e89b-12d3-a456-426614174000']);
    $server = Server::query()->findOrFail(1);
    $c->run(request($input), $server);
    $c->run(request($input), $server);
    eq(Run::query()->count(), 1);
    eq(count(array_filter($g->events, fn ($e) => $e[0] === 'delete')), 1);
});
test('restore request retries cannot replace files twice',function () {
    resetRuns();
    $g = new FakeGateway;
    app()->instance(Gateway::class,$g);
    $run = Run::query()->create(['server_id' => 1, 'label' => 'Restore', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now(), 'status' => 'completed', 'backup_uuid' => 'fake-backup-uuid', 'original_environment' => []]);
    $c = new FeaturesController;
    $input = ['confirm' => true, 'request_uuid' => '423e4567-e89b-12d3-a456-426614174000'];
    $server = Server::query()->findOrFail(1);
    $c->restore(adminRequest($input),$server,$run->id);
    $c->restore(adminRequest($input),$server,$run->id);
    eq(Restoration::query()->count(),1);
    eq(count(array_filter($g->events,fn ($e) => $e[0] === 'restore')),1);
});
test('nonempty zero-valued RCON secret is retained', function () { $controller=new FeaturesController; $controller->saveSettings(request(['options'=>[],'rcon_password'=>'0']),Server::query()->findOrFail(1));eq(Settings::forServer(1)->rcon_password,'0');eq(Settings::forServer(1)->publicData()['rcon_configured'],true); });
test('invalid nonempty webhook is rejected instead of silently ignored', function () { raises(fn()=>(new FeaturesController)->saveSettings(request(['options'=>[],'discord_webhook'=>'0']),Server::query()->findOrFail(1)),'discord.com'); });
Carbon::setTestNow();
