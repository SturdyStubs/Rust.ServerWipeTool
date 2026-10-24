<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Models\Server;
use RustServerWiper\Http\ConfigurationController;
use RustServerWiper\Http\HealthController;
use RustServerWiper\Http\WipeInput;
use RustServerWiper\Http\WiperController;
use RustServerWiper\Models\AnnouncementDelivery;
use RustServerWiper\Models\Preset;
use RustServerWiper\Models\Restoration;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Models\WipeMap;
use RustServerWiper\Services\Recurrence;
use RustServerWiper\Services\WipeEngine;

// The regression is anchored to the server clock, never the machine running this suite.
test('one minute ahead in Phoenix is accepted independently of PHP default timezone', function () {
    $original = date_default_timezone_get();
    try {
        date_default_timezone_set('Europe/Paris');
        Carbon::setTestNow(Carbon::parse('2026-10-14 21:20:30 UTC'));
        $parsed = WipeInput::parse(Request::create('/', 'POST', input(['timezone' => 'America/Phoenix', 'time' => '2026-10-14T14:21'])));
        eq($parsed['next_run_at']->format('Y-m-d H:i:s T'), '2026-10-14 21:21:00 UTC');
    } finally {
        date_default_timezone_set($original);
        Carbon::setTestNow();
    }
});
foreach (['America/Phoenix', 'Europe/Paris'] as $panelZone) {
    test('saved Phoenix 11:56 PM stays correct with panel timezone '.$panelZone, function () use ($panelZone) {
        resetRuns();
        $original = date_default_timezone_get();
        $appZone = config('app.timezone');
        try {
            config(['app.timezone' => $panelZone]);
            date_default_timezone_set($panelZone);
            Carbon::setTestNow(Carbon::parse('2026-10-15T06:55:00Z'));
            $gateway = new FakeGateway;
            $controller = new WiperController($gateway, new WipeEngine($gateway));
            $response = $controller->store(adminRequest(input(['timezone' => 'America/Phoenix', 'time' => '2026-10-14T23:56'])), Server::query()->findOrFail(1))->getData(true);
            $wipe = Wipe::query()->findOrFail($response['id']);
            eq($wipe->getRawOriginal('next_run_at'), '2026-10-15 06:56:00');
            eq($wipe->next_run_at->timestamp, 1792047360);
            eq($wipe->next_run_at->setTimezone('America/Phoenix')->format('Y-m-d H:i'), '2026-10-14 23:56');
            eq($response['next_run_at'], '2026-10-15T06:56:00.000000Z');
            $overview = $controller->index(adminRequest(), Server::query()->findOrFail(1))->getData(true);
            $saved = array_values(array_filter($overview['wipes'], fn ($row) => $row['id'] === $wipe->id))[0];
            eq($saved['next_run_at'], $response['next_run_at']);
            eq($overview['calendar']['events'][0]['at'], '2026-10-15T06:56:00+00:00');
        } finally {
            date_default_timezone_set($original);
            config(['app.timezone' => $appZone]);
            Carbon::setTestNow();
        }
    });
}

test('operational timestamps and offset inputs use UTC independently of panel timezone', function () {
    $original = date_default_timezone_get();
    try {
        date_default_timezone_set('America/Phoenix');
        foreach ([Wipe::class => ['next_run_at'], Run::class => ['due_at', 'not_before', 'started_at', 'finished_at'], Restoration::class => ['finished_at'], AnnouncementDelivery::class => ['expires_at']] as $class => $fields) {
            foreach ([...$fields, 'created_at', 'updated_at'] as $field) {
                $model = new $class;
                $model->setRawAttributes([$field => '2026-10-15 06:56:00']);
                eq($model->$field->timestamp, 1792047360);
                eq($model->toArray()[$field], '2026-10-15T06:56:00.000000Z');
                $model->$field = new DateTimeImmutable('2026-10-14T23:56:00-07:00');
                eq($model->getAttributes()[$field], '2026-10-15 06:56:00');
                $model->$field = null;
                eq($model->$field, null);
            }
        }
    } finally {
        date_default_timezone_set($original);
    }
});

test('Phoenix schedule executes at the saved instant with a Phoenix panel timezone', function () {
    resetRuns();
    $original = date_default_timezone_get();
    $appZone = config('app.timezone');
    try {
        config(['app.timezone' => 'America/Phoenix']);
        date_default_timezone_set('America/Phoenix');
        Carbon::setTestNow(Carbon::parse('2026-10-15T06:55:00Z'));
        $gateway = new FakeGateway;
        $controller = new WiperController($gateway, new WipeEngine($gateway));
        $data = $controller->store(adminRequest(input(['timezone' => 'America/Phoenix', 'time' => '2026-10-14T23:56'])), Server::query()->findOrFail(1))->getData(true);
        eq(tick($gateway), 0);
        eq(Run::query()->count(), 0);
        Carbon::setTestNow(Carbon::parse('2026-10-15T06:56:00Z'));
        eq(tick($gateway), 0);
        $run = Run::query()->where('wipe_id', $data['id'])->firstOrFail();
        eq($run->due_at->timestamp, 1792047360);
        eq($run->status, 'completed');
        eq($run->getRawOriginal('created_at'), '2026-10-15 06:56:00');
        eq(Wipe::query()->findOrFail($data['id'])->next_run_at, null);
    } finally {
        date_default_timezone_set($original);
        config(['app.timezone' => $appZone]);
        Carbon::setTestNow();
    }
});

test('past-time validation explains the timezone and server time', function () {
    Carbon::setTestNow('2026-10-14 21:20:30 UTC');
    try {
        raises(fn () => WipeInput::parse(Request::create('/', 'POST', input(['timezone' => 'America/Phoenix', 'time' => '2026-10-14T14:20']))), 'Server time in that timezone is 2026-10-14 14:20:30');
    } finally {
        Carbon::setTestNow();
    }
});
test('weekly recurrence crosses month boundaries without changing weekday', function () {
    eq(Recurrence::next(['weekly 4 14:00'], 'America/Phoenix', new DateTimeImmutable('2026-10-29 21:00 UTC'))->format('Y-m-d H:i'), '2026-11-05 21:00');
});
test('fourteen-day recurrence stays anchored across a five-week month', function () {
    eq(Recurrence::next(['fortnightly 2026-10-01 14:00'], 'America/Phoenix', new DateTimeImmutable('2026-10-29 21:00 UTC'))->format('Y-m-d H:i'), '2026-11-12 21:00');
});
test('interval recurrence preserves wall time over daylight savings', function () {
    eq(Recurrence::next(['fortnightly 2026-10-25 14:00'], 'America/New_York', new DateTimeImmutable('2026-10-25 18:00 UTC'))->format('Y-m-d H:i'), '2026-11-08 19:00');
});
test('weekly recurrence skips nonexistent local times', function () {
    eq(Recurrence::next(['weekly 7 02:30'], 'America/New_York', new DateTimeImmutable('2026-03-07 12:00 UTC'))->format('Y-m-d H:i'), '2026-03-15 06:30');
});
test('mixed monthly and weekly rules choose the earliest future occurrence', function () {
    eq(Recurrence::next(['weekly 4 14:00', '1 7 12:00'], 'UTC', new DateTimeImmutable('2026-10-31 00:00 UTC'))->format('Y-m-d H:i'), '2026-11-01 12:00');
});
test('invalid fortnight anchors rejected even for a preview', function () {
    raises(fn () => WipeInput::parse(Request::create('/', 'POST', input(['repeat' => ['fortnightly 2026-02-30 14:00'], 'time' => ''])), true));
});
test('skip advances one occurrence and cannot accidentally skip twice', function () {
    resetRuns();
    $wipe = schedule(['repeat' => ['weekly 4 14:00'], 'next_run_at' => '2026-10-08 14:00:00']);
    $g = new FakeGateway;
    $controller = new WiperController($g, new WipeEngine($g));
    $server = Server::query()->findOrFail(1);
    $r = adminRequest(['expected_at' => '2026-10-08T14:00:00Z']);
    $controller->skip($r, $server, $wipe->id);
    eq($wipe->fresh()->next_run_at->format('Y-m-d H:i'), '2026-10-15 14:00');
    eq(Run::query()->first()->status, 'skipped');
    raises(fn () => $controller->skip($r, $server, $wipe->id), 'next wipe changed');
    eq(Run::query()->count(), 1);
    eq($g->events, []);
});
test('skip of one-time schedule never queues a replacement', function () {
    resetRuns();
    $wipe = schedule(['repeat' => [], 'next_run_at' => '2026-10-08 14:00:00']);
    $g = new FakeGateway;
    (new WiperController($g, new WipeEngine($g)))->skip(adminRequest(['expected_at' => '2026-10-08T14:00:00Z']), Server::query()->findOrFail(1), $wipe->id);
    eq($wipe->fresh()->next_run_at, null);
    eq($g->events, []);
});
test('health check performs no power, backup, delete or startup mutations', function () {
    resetRuns();
    $g = new FakeGateway;
    $result = (new HealthController($g, new WipeEngine($g)))->check(adminRequest(input(['payload' => array_replace(payload(), ['backup' => true])])), Server::query()->findOrFail(1))->getData(true);
    $checks = array_column($result['checks'], null, 'id');
    eq($checks['plan']['status'], 'pass');
    eq($checks['backup']['status'], 'pass');
    eq($checks['worker']['status'], 'warning');
    eq($checks['cache']['status'], 'fail');
    eq($g->events, []);
    eq(isset($result['probe_id']), true);
});
test('health check reports exhausted backup quota before server stop', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->capacity = ['limit' => 1, 'used' => 1];
    $result = (new HealthController($g, new WipeEngine($g)))->check(adminRequest(input(['payload' => array_replace(payload(), ['backup' => true])])), Server::query()->findOrFail(1))->getData(true);
    eq(array_column($result['checks'], null, 'id')['backup']['status'], 'fail');
    eq($g->events, []);
});
test('health check reports unreachable maps and RCON without exposing secrets', function () {
    resetRuns();
    $g = new FakeGateway;
    $g->failMap = true;
    $g->unknownPlayers = true;
    $result = (new HealthController($g, new WipeEngine($g)))->check(adminRequest(input(['payload' => array_replace(payload(), ['level' => 'https://example.com/map?token=secret', 'readiness' => 'rcon'])])), Server::query()->findOrFail(1))->getData(true);
    eq(array_column($result['checks'], null, 'id')['plan']['status'], 'fail');
    eq(array_column($result['checks'], null, 'id')['rcon']['status'], 'fail');
    eq(str_contains(json_encode($result), 'token=secret'), false);
    eq($g->events, []);
});
test('health probes are scoped to the selected server', function () {
    resetRuns();
    $g = new FakeGateway;
    $id = '523e4567-e89b-12d3-a456-426614174000';
    Cache::put('rust-server-wiper:probe:2:'.$id, true, 300);
    eq((new HealthController($g, new WipeEngine($g)))->probe(adminRequest(), Server::query()->findOrFail(1), $id)->getData(true)['acknowledged'], false);
});
function transferFixture(): array
{
    return ['format' => 'rust-server-wiper.config', 'version' => 1,
        'schedules' => [['label' => 'Weekly import', 'timezone' => 'America/Phoenix', 'repeat' => ['weekly 4 14:00'], 'time' => '', 'payload' => payload()]],
        'presets' => [['name' => 'Imported preset', 'payload' => payload()]],
        'maps' => [['name' => 'Imported map', 'url' => 'https://example.com/imported.map', 'weight' => 2, 'position' => 3]]];
}
test('configuration preview adapts paths but writes nothing', function () {
    resetRuns();
    $counts = [Wipe::query()->count(), Preset::query()->count(), WipeMap::query()->count()];
    $data = (new ConfigurationController)->preview(adminRequest(['configuration' => transferFixture(), 'target_identity' => '/server/destination']), Server::query()->findOrFail(1))->getData(true);
    eq($data['schedules'][0]['payload']['files'], '/server/destination/*.sav');
    eq($data['schedules'][0]['enabled'], false);
    eq(is_string($data['schedules'][0]['next_run_at']), true);
    eq([Wipe::query()->count(), Preset::query()->count(), WipeMap::query()->count()], $counts);
});
test('configuration import is paused, atomic and idempotent', function () {
    resetRuns();
    $controller = new ConfigurationController;
    $server = Server::query()->findOrFail(1);
    $before = Preset::query()->count();
    $r = adminRequest(['configuration' => transferFixture(), 'target_identity' => '/server/destination', 'confirm' => true, 'request_uuid' => '623e4567-e89b-12d3-a456-426614174000']);
    $controller->import($r, $server);
    $controller->import($r, $server);
    eq(Wipe::query()->count(), 1);
    eq(Wipe::query()->first()->enabled, false);
    eq(Preset::query()->count(), $before + 1);
    eq(Wipe::query()->first()->server_id, 1);
    eq(Run::query()->count(), 0);
    $changed = $r->all();
    $changed['target_identity'] = '/server/other';
    raises(fn () => $controller->import(adminRequest($changed), $server), 'different content');
});
test('configuration rejects malformed paths and leaves no partial records', function () {
    resetRuns();
    $config = transferFixture();
    $config['schedules'][0]['payload']['files'] = '/server/../secret';
    $counts = [Wipe::query()->count(), Preset::query()->count(), WipeMap::query()->count()];
    raises(fn () => (new ConfigurationController)->import(adminRequest(['configuration' => $config, 'target_identity' => '/server/new', 'confirm' => true, 'request_uuid' => '723e4567-e89b-12d3-a456-426614174000']), Server::query()->findOrFail(1)));
    eq([Wipe::query()->count(), Preset::query()->count(), WipeMap::query()->count()], $counts);
});
test('expired one-time imports stay paused and require a new date', function () {
    resetRuns();
    $config = transferFixture();
    $config['schedules'][0]['repeat'] = [];
    $config['schedules'][0]['time'] = '2000-01-01T12:00';
    $data = (new ConfigurationController)->preview(adminRequest(['configuration' => $config, 'target_identity' => '/server/new']), Server::query()->findOrFail(1))->getData(true);
    eq($data['schedules'][0]['next_run_at'], null);
    eq(count($data['warnings']) > 1, true);
});
test('exports are scoped and omit connection secrets, commands and URL tokens', function () {
    resetRuns();
    schedule(['payload' => array_replace(payload(), ['level' => 'https://example.com/a.map?token=private', 'commands' => [['minutes' => 5, 'command' => 'secret-console-command']]])]);
    schedule(['server_id' => 2, 'label' => 'Other server private']);
    $output = (new ConfigurationController)->export(adminRequest(), Server::query()->findOrFail(1))->getData(true);
    eq(count($output['schedules']), 1);
    eq($output['schedules'][0]['payload']['commands'], []);
    eq($output['schedules'][0]['payload']['level'], 'https://example.com/a.map');
    eq(str_contains(json_encode($output), 'secret-console-command'), false);
    eq(isset($output['settings']), false);
});
test('read-only users cannot import, export, skip or run health checks', function () {
    $server = Server::query()->findOrFail(1);
    $g = new FakeGateway;
    raises(fn () => (new ConfigurationController)->export(request([], false), $server));
    raises(fn () => (new ConfigurationController)->preview(request([], false), $server));
    raises(fn () => (new ConfigurationController)->import(request([], false), $server));
    raises(fn () => (new HealthController($g, new WipeEngine($g)))->check(request([], false), $server));
    raises(fn () => (new WiperController($g, new WipeEngine($g)))->skip(request([], false), $server, 1));
});
Carbon::setTestNow();

test('configuration limits roll back records created earlier in the transaction', function () {
    resetRuns();
    WipeMap::query()->where('server_id', 1)->delete();
    for ($i = 0; $i < 100; $i++) {
        WipeMap::query()->create(['server_id' => 1, 'name' => 'Existing '.$i, 'url' => 'https://example.com/a.map']);
    }
    $presetCount = Preset::query()->count();
    raises(fn () => (new ConfigurationController)->import(adminRequest(['configuration' => transferFixture(), 'target_identity' => '/server/new', 'confirm' => true, 'request_uuid' => '823e4567-e89b-12d3-a456-426614174000']), Server::query()->findOrFail(1)), '100 maps');
    eq(Wipe::query()->count(), 0);
    eq(Preset::query()->count(), $presetCount);
    eq(WipeMap::query()->where('server_id', 1)->count(), 100);
});
test('skip refuses a schedule with a queued operation', function () {
    resetRuns();
    $wipe = schedule(['next_run_at' => '2026-10-08 14:00:00']);
    Run::query()->create(['wipe_id' => $wipe->id, 'server_id' => 1, 'label' => 'Active run', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now(), 'status' => 'queued']);
    $g = new FakeGateway;
    raises(fn () => (new WiperController($g, new WipeEngine($g)))->skip(adminRequest(['expected_at' => '2026-10-08T14:00:00Z']), Server::query()->findOrFail(1), $wipe->id), 'active run');
    eq($wipe->fresh()->next_run_at->format('Y-m-d'), '2026-10-08');
});
Carbon::setTestNow();
