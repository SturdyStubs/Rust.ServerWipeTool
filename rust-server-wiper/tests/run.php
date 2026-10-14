<?php
declare(strict_types=1);

// Uses the installed panel's real Laravel and models; all daemon operations are simulated.
$panel = realpath($argv[1] ?? dirname(__DIR__, 2).'/.reference-panel');
if (!$panel || !is_file($panel.'/vendor/autoload.php')) { fwrite(STDERR, "Usage: php tests/run.php /path/to/pterodactyl\n"); exit(1); }
$loader = require $panel.'/vendor/autoload.php';
$loader->addPsr4('RustServerWiper\\', dirname(__DIR__).'/src');
foreach (['APP_ENV' => 'testing', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'PTERODACTYL_EXTENSIONS_ENABLED' => 'false'] as $key => $value) {
    putenv("$key=$value"); $_ENV[$key] = $value; $_SERVER[$key] = $value;
}
$app = require $panel.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/FakeGateway.php';

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use RustServerWiper\Console\TickCommand;
use RustServerWiper\Http\WipeInput;
use RustServerWiper\Http\WiperController;
use RustServerWiper\Jobs\ExecuteWipe;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\Paths;
use RustServerWiper\Services\Recurrence;
use RustServerWiper\Services\WipeEngine;

$passed = 0; $failed = 0;
function test(string $name, callable $fn): void { global $passed, $failed; try { $fn(); $passed++; echo "PASS $name\n"; } catch (Throwable $e) { $failed++; echo "FAIL $name: ".$e->getMessage()."\n"; } }
function eq($actual, $expected): void { if ($actual !== $expected) throw new RuntimeException('Expected '.json_encode($expected).', got '.json_encode($actual)); }
function raises(callable $fn, string $contains = ''): void { try { $fn(); } catch (Throwable $e) { if ($contains && !str_contains($e->getMessage(), $contains)) throw $e; return; } throw new RuntimeException('Expected rejection.'); }
function payload(): array { return ['name' => null, 'description' => null, 'seed' => null, 'size' => null, 'level' => null, 'random_seed' => false, 'random_level' => false, 'files' => '/server/rust/*.sav', 'blueprints' => false, 'identity_path' => '/server/rust', 'commands' => []]; }
function execute(FakeGateway $g, array $p = [], array $maps = [], ?callable $allowed = null): array { return (new WipeEngine($g))->execute(1, array_replace(payload(), $p), 'UTC', $maps, $allowed ?? fn () => true, fn () => null); }
function hasEvent(FakeGateway $g, string $event): bool { return count(array_filter($g->events, fn ($e) => $e[0] === $event)) > 0; }
function input(array $overrides = []): array { return array_replace(['label' => 'Test wipe', 'timezone' => 'UTC', 'enabled' => true, 'time' => '2030-01-01T12:00', 'repeat' => [], 'payload' => payload()], $overrides); }

test('fifth weekdays skip missing months', function () { eq(Recurrence::next(['5 1 12:00'], 'UTC', new DateTimeImmutable('2026-02-01'))->format('Y-m-d H:i'), '2026-03-30 12:00'); });
test('passed fifth weekday does not overflow next month', function () { eq(Recurrence::next(['5 1 12:00'], 'UTC', new DateTimeImmutable('2026-03-30 12:01'))->format('Y-m-d H:i'), '2026-06-29 12:00'); });
test('first Thursday tracks local timezone', function () { eq(Recurrence::next(['1 4 14:00'], 'America/Phoenix', new DateTimeImmutable('2026-10-01 22:00 UTC'))->format('Y-m-d H:i'), '2026-11-05 21:00'); });
test('DST gap recurrence skipped', function () { eq(Recurrence::next(['2 7 02:30'], 'America/New_York', new DateTimeImmutable('2026-03-01'))->format('Y-m-d H:i'), '2026-04-12 06:30'); });
test('earliest rule wins independent of order', function () { eq(Recurrence::next(['3 4 14:00', '1 4 14:00'], 'UTC', new DateTimeImmutable('2026-10-01 00:00'))->format('Y-m-d H:i'), '2026-10-01 14:00'); });
test('strictly after due minute prevents repeat duplication', function () { eq(Recurrence::next(['1 4 14:00'], 'UTC', new DateTimeImmutable('2026-10-01 14:00'))->format('Y-m-d H:i'), '2026-11-05 14:00'); });
test('one-time conversion uses chosen timezone', function () { eq(Recurrence::localTime('2026-10-01T14:00', 'America/Phoenix')->format('H:i'), '21:00'); });
test('invalid date and DST gap rejected', function () { raises(fn () => Recurrence::localTime('2026-02-30T12:00', 'UTC')); raises(fn () => Recurrence::localTime('2026-03-08T02:30', 'America/New_York')); });
test('bad repeat rules rejected', function () { raises(fn () => Recurrence::next(['0 8 25:00'], 'UTC', new DateTimeImmutable)); });
foreach (['/', '/server', '/../server/*.sav', '/server/rust/../cfg', '/server/*/world.sav', '/server/rust/*', '/server//rust/file', '/server/rust/file?'] as $bad) test("unsafe path $bad", fn () => raises(fn () => Paths::normalize($bad)));
test('CRLF and duplicate patterns normalized', function () { eq(Paths::patterns(['files' => " /server/rust/*.sav\r\n/server/rust/*.sav\n\n"]), ['/server/rust/*.sav']); });
test('wildcards delete files and retain directories', function () { $g = new FakeGateway; eq(Paths::resolve(['/server/rust/*.sav'], fn ($r) => $g->list(1, $r)), ['/server/rust' => ['world.sav']]); });
test('symlink directory rejected before traversal', function () { $g = new FakeGateway; $g->directories['/server'][0]['symlink'] = true; raises(fn () => Paths::resolve(['/server/rust/*.sav'], fn ($r) => $g->list(1, $r)), 'Unsafe directory'); });
test('matching symlink rejected', function () { $g = new FakeGateway; $g->directories['/server/rust'][1]['symlink'] = true; raises(fn () => execute($g)); eq(hasEvent($g, 'power'), false); });
test('missing files produce empty plan', function () { $g = new FakeGateway; eq(Paths::resolve(['/server/missing/*.sav'], fn ($r) => $g->list(1, $r)), []); });
test('preview has no power or file mutations', function () { $g = new FakeGateway; eq((new WipeEngine($g))->preview(1, payload())['count'], 1); eq($g->events, []); });
test('stop delete sync start order', function () { $g = new FakeGateway; $result = execute($g); eq($result['deleted'], ['/server/rust/world.sav']); eq(array_column($g->events, 0), ['power', 'environment', 'delete', 'sync', 'power']); eq(end($g->events), ['power', 'start']); });
test('stuck server never deletes or updates', function () { $g = new FakeGateway; $g->stopWorks = false; raises(fn () => execute($g), 'did not stop'); eq(hasEvent($g, 'delete'), false); eq(hasEvent($g, 'environment'), false); });
test('missing variable rejected before stop', function () { $g = new FakeGateway; unset($g->env['DESCRIPTION']); raises(fn () => execute($g, ['description' => 'New']), 'Missing variable'); eq($g->events, []); });
test('empty rotation rejected before stop', function () { $g = new FakeGateway; raises(fn () => execute($g, ['random_level' => true]), 'empty'); eq($g->events, []); });
test('single map rotation supported', function () { $g = new FakeGateway; $g->env['MAP_URL'] = 'https://example.com/a.map'; execute($g, ['random_level' => true], ['https://example.com/a.map']); eq($g->env['MAP_URL'], 'https://example.com/a.map'); });
test('rotation excludes current and duplicate maps', function () { $g = new FakeGateway; $g->env['MAP_URL'] = 'https://example.com/a.map'; execute($g, ['random_level' => true], ['https://example.com/a.map', 'https://example.com/b.map', 'https://example.com/b.map']); eq($g->env['MAP_URL'], 'https://example.com/b.map'); });
test('optional values preserved', function () { $g = new FakeGateway; $before = $g->env; execute($g); eq($g->env, $before); });
test('seed zero respected and config override exact', function () { $g = new FakeGateway; execute($g, ['seed' => 0]); eq($g->env['WORLD_SEED'], '0'); eq($g->config, "// server.seed 999\nserver.seed 0\nserver.seedextra 456\n"); });
test('missing config allowed', function () { $g = new FakeGateway; $g->config = null; execute($g, ['seed' => 42]); eq($g->env['WORLD_SEED'], '42'); });
test('symlink config rejected before seed update', function () { $g = new FakeGateway; $g->directories['/server/rust/cfg'][0]['symlink'] = true; raises(fn () => execute($g, ['seed' => 42]), 'symlink'); eq($g->events, []); });
test('blueprint WAL included', function () { $g = new FakeGateway; $result = execute($g, ['blueprints' => true]); eq(in_array('/server/rust/player.blueprints.5.db-wal', $result['deleted']), true); });
test('failed deletion never starts server', function () { $g = new FakeGateway; $g->failDelete = true; raises(fn () => execute($g), 'Delete failed'); eq($g->powerState, 'offline'); });
test('failed sync never starts server', function () { $g = new FakeGateway; $g->failSync = true; raises(fn () => execute($g), 'Sync failed'); eq($g->powerState, 'offline'); });
test('disabled before execution does not stop server', function () { $g = new FakeGateway; raises(fn () => execute($g, [], [], fn () => false), 'disabled'); eq($g->events, []); });
test('disabled after stop never deletes', function () { $g = new FakeGateway; $calls = 0; raises(fn () => execute($g, [], [], function () use (&$calls) { return ++$calls === 1; }), 'disabled'); eq(hasEvent($g, 'delete'), false); });
test('upstream list errors are not hidden', function () { $g = new FakeGateway; $g->failList = true; raises(fn () => execute($g), 'disconnected'); eq($g->events, []); });
test('input cannot inject ownership or run state', function () { $data = WipeInput::parse(Request::create('/', 'POST', input(['server_id' => 99, 'status' => 'completed']))); eq(array_key_exists('server_id', $data), false); eq(array_key_exists('status', $data), false); });
test('invalid nested commands rejected', fn () => raises(fn () => WipeInput::parse(Request::create('/', 'POST', input(['payload' => array_replace(payload(), ['commands' => [['minutes' => -1, 'command' => 'say hello']]])])))));
test('custom and procedural options conflict rejected', fn () => raises(fn () => WipeInput::parse(Request::create('/', 'POST', input(['payload' => array_replace(payload(), ['level' => 'https://example.com/a.map', 'seed' => 42])])))));
test('past schedule rejected', fn () => raises(fn () => WipeInput::parse(Request::create('/', 'POST', input(['time' => '2000-01-01T00:00'])))));

// Minimal isolated panel schema. It is created only in the in-memory test database.
Schema::create('servers', function (Blueprint $t) { $t->increments('id'); $t->string('uuid')->nullable(); $t->unsignedInteger('egg_id')->default(1); $t->unsignedInteger('allocation_id')->nullable(); $t->string('timezone')->nullable(); $t->timestamps(); });
Schema::create('allocations', function (Blueprint $t) { $t->increments('id'); });
Schema::create('extensions', function (Blueprint $t) { $t->increments('id'); $t->string('identifier'); $t->boolean('enabled'); $t->string('version'); $t->timestamps(); });
DB::table('servers')->insert([['id' => 1, 'uuid' => 'test-one'], ['id' => 2, 'uuid' => 'test-two']]);
DB::table('extensions')->insert(['identifier' => 'rust-server-wiper', 'enabled' => true, 'version' => '2.0.0']);
$migration = require dirname(__DIR__).'/database/migrations/2026_10_13_000000_create_wiper_tables.php';
$migration->up();
$app->instance(ExtensionRepository::class, new class extends ExtensionRepository { public function __construct() {} public function isAvailable(string $identifier): bool { return true; } });
config(['extensions.enabled' => true]);
function resetRuns(): void { Run::query()->delete(); Wipe::query()->delete(); Cache::flush(); Carbon::setTestNow('2026-10-01 14:00:00 UTC'); }
function schedule(array $extra = []): Wipe { return Wipe::query()->create(array_replace(['server_id' => 1, 'label' => 'Test', 'timezone' => 'UTC', 'enabled' => true, 'payload' => payload(), 'repeat' => ['1 4 14:00'], 'next_run_at' => '2026-10-01 14:00:00'], $extra)); }
function tick(FakeGateway $gateway): int { app()->instance(Gateway::class, $gateway); $command = new TickCommand; $command->setLaravel(app()); $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput)); return $command->handle($gateway); }
function request(array $data = [], bool $can = true): Request { $r = Request::create('/', 'POST', $data); $r->setUserResolver(fn () => new class($can) { public function __construct(private bool $allowed) {} public function can($permission, $server): bool { return $this->allowed; } }); return $r; }

test('migration rerun preserves tables', function () use ($migration) { $migration->up(); eq(Schema::hasTable('ext_rust_server_wiper_runs'), true); });
test('scheduler records successful run and advances recurrence', function () { resetRuns(); $g = new FakeGateway; $wipe = schedule(); eq(tick($g), 0); eq(Run::query()->first()->status, 'completed'); eq($wipe->fresh()->next_run_at->format('Y-m-d H:i'), '2026-11-05 14:00'); });
test('same minute tick is idempotent', function () { resetRuns(); $g = new FakeGateway; schedule(); tick($g); tick($g); eq(Run::query()->count(), 1); eq(count(array_filter($g->events, fn ($e) => $e[0] === 'delete')), 1); });
test('late schedules skipped without deletion', function () { resetRuns(); $g = new FakeGateway; schedule(['next_run_at' => '2026-10-01 12:00:00']); tick($g); eq(Run::query()->first()->status, 'missed'); eq($g->events, []); });
test('one-time schedule consumed once', function () { resetRuns(); $g = new FakeGateway; $wipe = schedule(['repeat' => []]); tick($g); eq($wipe->fresh()->next_run_at, null); tick($g); eq(Run::query()->count(), 1); });
test('notice sent once per occurrence', function () { resetRuns(); $g = new FakeGateway; schedule(['next_run_at' => '2026-10-01 14:10:00', 'payload' => array_replace(payload(), ['commands' => [['minutes' => 10, 'command' => 'say wipe soon']]])]); tick($g); tick($g); eq($g->events, [['command', 'say wipe soon']]); });
test('failed wipe is durable and never replayed', function () { resetRuns(); $g = new FakeGateway; $g->stopWorks = false; schedule(); tick($g); eq(Run::query()->first()->status, 'failed'); tick($g); eq(Run::query()->count(), 1); });
test('per-server lock defers queued jobs', function () { resetRuns(); $g = new FakeGateway; schedule(); $lock = Cache::lock('rust-server-wiper:server:1', 900); $lock->get(); tick($g); eq(Run::query()->first()->status, 'queued'); $lock->release(); tick($g); eq(Run::query()->first()->status, 'completed'); });
test('disabled extension checked live by workers', function () { resetRuns(); $g = new FakeGateway; app()->instance(Gateway::class, $g); $r = Run::query()->create(['server_id' => 1, 'label' => 'Disabled', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()]); DB::table('extensions')->update(['enabled' => false]); (new ExecuteWipe($r->id))->handle(new WipeEngine($g)); eq($r->fresh()->status, 'cancelled'); eq($g->events, []); DB::table('extensions')->update(['enabled' => true]); });
test('server scoping prevents cross-server update deletion', function () { resetRuns(); $g = new FakeGateway; $wipe = schedule(['server_id' => 2]); $c = new WiperController($g, new WipeEngine($g)); raises(fn () => $c->delete(request(), Server::query()->findOrFail(1), $wipe->id)); eq(Wipe::query()->count(), 1); });
test('manage permission required', function () { $g = new FakeGateway; $c = new WiperController($g, new WipeEngine($g)); raises(fn () => $c->index(request([], false), Server::query()->findOrFail(1))); });
test('pausing cancels queued wipe', function () { resetRuns(); $g = new FakeGateway; $wipe = schedule(); $r = Run::query()->create(['server_id' => 1, 'wipe_id' => $wipe->id, 'label' => 'Queued', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()]); $c = new WiperController($g, new WipeEngine($g)); $c->toggle(request(['enabled' => false]), Server::query()->findOrFail(1), $wipe->id); eq($r->fresh()->status, 'cancelled'); eq($wipe->fresh()->enabled, false); });
test('deleting schedule cancels queued run and retains history', function () { resetRuns(); $g = new FakeGateway; $wipe = schedule(); $r = Run::query()->create(['server_id' => 1, 'wipe_id' => $wipe->id, 'label' => 'Queued', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()]); $c = new WiperController($g, new WipeEngine($g)); $c->delete(request(), Server::query()->findOrFail(1), $wipe->id); eq($r->fresh()->status, 'cancelled'); eq($r->fresh()->wipe_id, null); });
test('second queued manual wipe rejected', function () { resetRuns(); $g = new FakeGateway; Run::query()->create(['server_id' => 1, 'label' => 'Queued', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()]); $c = new WiperController($g, new WipeEngine($g)); raises(fn () => $c->run(request(input(['confirm' => true])), Server::query()->findOrFail(1)), 'already queued'); });

test('queued job expires without deleting after queue downtime', function () { resetRuns(); $g = new FakeGateway; Run::query()->create(['server_id' => 1, 'label' => 'Late', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()->subHour(), 'created_at' => now()->subHour()]); tick($g); eq(Run::query()->first()->status, 'missed'); eq($g->events, []); });
test('stale running job fails without replay', function () { resetRuns(); $g = new FakeGateway; Run::query()->create(['server_id' => 1, 'label' => 'Interrupted', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now()->subHour(), 'status' => 'running', 'started_at' => now()->subHour()]); tick($g); eq(Run::query()->first()->status, 'failed'); eq($g->events, []); });
test('cross-server map deletion blocked', function () { $g = new FakeGateway; $map = RustServerWiper\Models\WipeMap::query()->create(['server_id' => 2, 'name' => 'Other server', 'url' => 'https://example.com/a.map']); $c = new WiperController($g, new WipeEngine($g)); raises(fn () => $c->deleteMap(request(), Server::query()->findOrFail(1), $map->id)); eq(RustServerWiper\Models\WipeMap::query()->whereKey($map->id)->exists(), true); $map->delete(); });

Schema::create('wipes', function (Blueprint $t) { $t->id(); $t->unsignedInteger('server_id'); $t->string('name'); $t->text('description'); $t->integer('size')->nullable(); $t->integer('seed')->nullable(); $t->boolean('random_seed'); $t->boolean('random_level'); $t->text('level')->nullable(); $t->text('files'); $t->boolean('blueprints'); $t->text('repeat'); $t->dateTime('time')->nullable(); $t->dateTime('ran_at')->nullable(); });
Schema::create('wipe_maps', function (Blueprint $t) { $t->id(); $t->unsignedInteger('server_id'); $t->string('name'); $t->text('map'); });
function importLegacy(bool $dry = false): int { $command = new RustServerWiper\Console\ImportLegacyCommand; $command->setLaravel(app()); return $command->run(new Symfony\Component\Console\Input\ArrayInput($dry ? ['--dry-run' => true] : []), new Symfony\Component\Console\Output\BufferedOutput); }
DB::table('wipes')->insert(['server_id' => 1, 'name' => 'Old Rust', 'description' => 'Old description', 'size' => 3500, 'seed' => 42, 'random_seed' => false, 'random_level' => false, 'files' => "/server/rust/*.sav\r\n/server/rust/*.map", 'blueprints' => true, 'repeat' => '["1 4 14:00"]']);
DB::table('wipe_maps')->insert(['server_id' => 1, 'name' => 'Old map', 'map' => 'https://example.com/a.map']);
test('legacy dry-run does not write data', function () { resetRuns(); eq(importLegacy(true), 0); eq(Wipe::query()->count(), 0); eq(RustServerWiper\Models\WipeMap::query()->count(), 0); });
test('legacy import preserves originals and pauses schedules', function () { eq(importLegacy(), 0); eq(Wipe::query()->count(), 1); eq(Wipe::query()->first()->enabled, false); eq(Wipe::query()->first()->payload['blueprints'], true); eq(DB::table('wipes')->count(), 1); eq(DB::table('wipe_maps')->count(), 1); });
test('legacy import is idempotent', function () { eq(importLegacy(), 0); eq(Wipe::query()->count(), 1); eq(RustServerWiper\Models\WipeMap::query()->count(), 1); });

Carbon::setTestNow();
echo "\n$passed passed, $failed failed.\n";
exit($failed ? 1 : 0);
