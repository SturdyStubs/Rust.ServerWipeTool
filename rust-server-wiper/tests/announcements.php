<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Server;
use RustServerWiper\Http\AnnouncementsController;
use RustServerWiper\Jobs\ExecuteWipe;
use RustServerWiper\Jobs\SendAnnouncement;
use RustServerWiper\Models\AnnouncementDelivery;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Services\AnnouncementConfig;
use RustServerWiper\Services\AnnouncementMessage;
use RustServerWiper\Services\Announcements;
use RustServerWiper\Services\Gateway;
use RustServerWiper\Services\WipeEngine;

function announcementId(int $n): string
{
    return sprintf('10000000-0000-4000-8000-%012d', $n);
}
function announcementFixture(): array
{
    resetRuns();
    AnnouncementDelivery::query()->delete();
    DB::table('ext_rust_server_wiper_settings')->where('server_id', 1)->update(['announcement_config' => null]);
    Bus::fake([SendAnnouncement::class]);
    Http::swap(new Factory);
    Http::fake(['*' => Http::response(['id' => 'discord-message'], 200)]);
    $config = ['revision' => 0, 'enabled' => true,
        'webhooks' => [
            ['id' => announcementId(1), 'name' => 'Community', 'url' => 'https://discord.com/api/webhooks/123/community-secret', 'enabled' => true],
            ['id' => announcementId(2), 'name' => 'Staff', 'url' => 'https://discord.com/api/webhooks/456/staff-secret', 'enabled' => true]],
        'presets' => [['id' => announcementId(3), 'name' => 'Reminder', 'content' => '{server}: {label} wipes {relative}. {timestamp} ({epoch}), {minutes} minutes.', 'embeds' => [], 'role_ids' => [], 'mention_everyone' => false]],
        'rules' => [['id' => announcementId(4), 'name' => '30 minute reminder', 'enabled' => true, 'event' => 'before', 'minutes' => 30, 'preset_id' => announcementId(3), 'webhook_ids' => [announcementId(1), announcementId(2)], 'wipe_id' => null]]];
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));

    return Settings::forServer(1)->announcement_config;
}
function announceReminder(string $key = 'occurrence'): void
{
    (new Announcements)->emit(1, 'before', $key, ['label' => 'Monthly', 'minutes' => 30, 'epoch' => now('UTC')->addMinutes(30)->timestamp]);
}

test('modular webhook URLs and delivery payloads stay encrypted and hidden', function () {
    announcementFixture();
    $raw = DB::table('ext_rust_server_wiper_settings')->where('server_id', 1)->value('announcement_config');
    eq(str_contains($raw, 'community-secret'), false);
    $data = (new AnnouncementsController)->index(adminRequest(), Server::query()->findOrFail(1))->getData(true);
    eq($data['configuration']['webhooks'][0]['url'], '');
    eq($data['configuration']['webhooks'][0]['configured'], true);
    eq(isset(Settings::forServer(1)->toArray()['announcement_config']), false);
    announceReminder();
    eq(str_contains(DB::table('ext_rust_server_wiper_notifications')->first()->delivery_payload, 'Monthly'), false);
    eq(isset(AnnouncementDelivery::query()->first()->toArray()['delivery_payload']), false);
});
test('blank webhook fields preserve secrets and stale saves are rejected', function () {
    $config = announcementFixture();
    foreach ($config['webhooks'] as &$hook) {
        $hook['url'] = '';
    }unset($hook);
    $controller = new AnnouncementsController;
    $server = Server::query()->findOrFail(1);
    $controller->save(adminRequest($config), $server);
    eq(Settings::forServer(1)->announcement_config['webhooks'][0]['url'], 'https://discord.com/api/webhooks/123/community-secret');
    raises(fn () => $controller->save(adminRequest($config), $server), 'another session');
});
test('text-only and embed-only messages are valid but an empty message is rejected', function () {
    announcementFixture();
    eq(AnnouncementMessage::validate(['content' => 'Hello', 'embeds' => []])['content'], 'Hello');
    eq(count(AnnouncementMessage::validate(['content' => '', 'embeds' => [['description' => 'Embed only', 'fields' => [['name' => 'When', 'value' => '{timestamp}', 'inline' => true]]]]])['embeds']), 1);
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => []]), 'Add message content');
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => [['color' => 123]]]), 'Add message content');
});
test('embeds enforce individual and combined Discord limits before delivery', function () {
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => [['title' => str_repeat('x', 257)]]]));
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => [['description' => str_repeat('x', 4000)], ['description' => str_repeat('x', 3000)]]]), '6,000');
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => array_fill(0, 11, ['title' => 'x'])]));
    raises(fn () => AnnouncementMessage::validate(['content' => '', 'embeds' => [['image' => ['url' => 'javascript:alert(1)']]]]));
});
test('multiple rules and channels receive independent at-most-once deliveries', function () {
    $config = announcementFixture();
    $rule = $config['rules'][0];
    $rule['id'] = announcementId(5);
    $config['rules'][] = $rule;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    announceReminder();
    announceReminder();
    eq(AnnouncementDelivery::query()->count(), 4);
    eq(Bus::dispatched(SendAnnouncement::class)->count(), 4);
    foreach (AnnouncementDelivery::query()->get() as $delivery) {
        (new SendAnnouncement($delivery->id))->handle();
    }
    eq(Http::recorded()->count(), 4);
    foreach (AnnouncementDelivery::query()->get() as $delivery) {
        eq($delivery->status, 'sent');
    }
    (new SendAnnouncement(AnnouncementDelivery::query()->first()->id))->handle();
    eq(Http::recorded()->count(), 4);
});
test('multiple reminder offsets fire separately and repeated scheduler ticks do not duplicate them', function () {
    $config = announcementFixture();
    $rule = $config['rules'][0];
    $rule['id'] = announcementId(6);
    $rule['minutes'] = 5;
    $config['rules'][] = $rule;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    schedule(['next_run_at' => '2026-10-01 14:30:00']);
    $g = new FakeGateway;
    tick($g);
    tick($g);
    eq(AnnouncementDelivery::query()->count(), 2);
    eq($g->events, []);
    Carbon::setTestNow('2026-10-01 14:25:00 UTC');
    tick($g);
    eq(AnnouncementDelivery::query()->count(), 4);
});
test('reminder rules can be scoped to one schedule and cannot select another server', function () {
    $config = announcementFixture();
    $wipe = schedule(['next_run_at' => '2026-10-01 14:30:00']);
    $other = schedule(['server_id' => 2, 'next_run_at' => '2026-10-01 14:30:00']);
    $config['rules'][0]['wipe_id'] = $other->id;
    raises(fn () => (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1)), 'this server');
    $config['rules'][0]['wipe_id'] = $wipe->id;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    (new Announcements)->emit(1, 'before', 'different', ['wipe_id' => 999, 'minutes' => 30, 'epoch' => now()->addMinutes(30)->timestamp]);
    eq(AnnouncementDelivery::query()->count(), 0);
    (new Announcements)->reminder($wipe, now('UTC'));
    eq(AnnouncementDelivery::query()->count(), 2);
});
test('rendered tokens and embeds reach Discord with explicit mention controls', function () {
    $config = announcementFixture();
    $config['presets'][0]['content'] = '@everyone {label} {timestamp}';
    $config['presets'][0]['role_ids'] = ['12345'];
    $config['presets'][0]['embeds'] = [['title' => '{server}', 'description' => '{relative}', 'footer' => ['text' => '{event}'], 'timestamp' => '{iso_time}']];
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    announceReminder();
    $delivery = AnnouncementDelivery::query()->first();
    (new SendAnnouncement($delivery->id))->handle();
    eq(Http::recorded(fn ($request) => str_ends_with($request->url(), '?wait=true') && str_contains($request['content'], '<@&12345> @everyone Monthly <t:') && $request['allowed_mentions']['parse'] === [] && $request['allowed_mentions']['roles'] === ['12345'] && $request['embeds'][0]['title'] === 'Rust One')->count(), 1);
});
test('preview validates messages without posting webhooks or queueing announcements', function () {
    $config = announcementFixture();
    $controller = new AnnouncementsController;
    $data = $controller->preview(adminRequest(['preset' => $config['presets'][0], 'event' => 'before', 'minutes' => 60]), Server::query()->findOrFail(1))->getData(true);
    eq(str_contains($data['payload']['content'], '60 minutes'), true);
    eq(Http::recorded()->count(), 0);
    eq(Bus::dispatched(SendAnnouncement::class)->count(), 0);
});
test('disabled or removed rules cancel queued messages without contacting Discord', function () {
    $config = announcementFixture();
    announceReminder();
    $config['rules'][0]['enabled'] = false;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    $delivery = AnnouncementDelivery::query()->first();
    (new SendAnnouncement($delivery->id))->handle();
    eq($delivery->fresh()->status, 'cancelled');
    eq(Http::recorded()->count(), 0);
});
test('stale reminders expire instead of announcing after the scheduled wipe', function () {
    announcementFixture();
    announceReminder();
    Carbon::setTestNow(now('UTC')->addMinutes(6));
    $delivery = AnnouncementDelivery::query()->first();
    (new SendAnnouncement($delivery->id))->handle();
    eq($delivery->fresh()->status, 'cancelled');
    eq(Http::recorded()->count(), 0);
});
test('Discord rate limits retry only an explicitly rejected unsent message', function () {
    announcementFixture();
    announceReminder();
    Http::swap(new Factory);
    Http::fakeSequence()->push(['retry_after' => 2], 429)->push(['id' => 'sent'], 200);
    $delivery = AnnouncementDelivery::query()->first();
    $job = new SendAnnouncement($delivery->id);
    $job->handle();
    eq($delivery->fresh()->status, 'queued');
    Carbon::setTestNow(now('UTC')->addSeconds(3));
    $job->handle();
    eq($delivery->fresh()->status, 'sent');
});
test('ambiguous Discord failures are isolated and never retried automatically', function () {
    announcementFixture();
    announceReminder();
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('private webhook token');
    });
    $delivery = AnnouncementDelivery::query()->first();
    $job = new SendAnnouncement($delivery->id);
    $job->handle();
    $job->handle();
    eq($attempts, 1);
    eq($delivery->fresh()->status, 'failed');
    eq(str_contains($delivery->fresh()->message, 'private webhook token'), false);
});
test('wiping and wiped events occur before stop and after observed running respectively', function () {
    announcementFixture();
    $g = new FakeGateway;
    $events = [];
    (new WipeEngine($g))->execute(1, payload(), 'UTC', [], fn () => true, function ($message, $result = null, $meta = []) use (&$events, $g) {
        if (isset($meta['event'])) {
            $events[] = [$meta['event'], $g->powerState, hasEvent($g, 'delete')];
        }
    }, ['observe_running' => true]);
    eq($events, [['wiping', 'running', false], ['wiped', 'running', true]]);
});
test('a failed stop never emits a wiped event', function () {
    announcementFixture();
    $g = new FakeGateway;
    $g->stopWorks = false;
    $events = [];
    raises(function () use ($g, &$events) {
        (new WipeEngine($g))->execute(1, payload(), 'UTC', [], fn () => true, function ($message, $result = null, $meta = []) use (&$events) {
            if (isset($meta['event'])) {
                $events[] = $meta['event'];
            }
        }, ['observe_running' => true]);
    }, 'did not stop');
    eq($events, ['wiping']);
});
test('start acceptance alone does not emit wiped when the process never runs', function () {
    announcementFixture();
    $g = new FakeGateway;
    $g->startWorks = false;
    $events = [];
    $result = (new WipeEngine($g))->execute(1, payload(), 'UTC', [], fn () => true, function ($message, $result = null, $meta = []) use (&$events) {
        if (isset($meta['event'])) {
            $events[] = $meta['event'];
        }
    }, ['observe_running' => true]);
    eq($events, ['wiping']);
    eq($result['readiness'], 'timed_out');
    eq(hasEvent($g, 'delete'), true);
});
test('the real wipe job queues shutdown and running announcements on a separate queue', function () {
    $config = announcementFixture();
    $config['rules'][0]['event'] = 'wiping';
    $config['rules'][0]['minutes'] = null;
    $second = $config['rules'][0];
    $second['id'] = announcementId(7);
    $second['event'] = 'wiped';
    $config['rules'][] = $second;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    $g = new FakeGateway;
    app()->instance(Gateway::class, $g);
    schedule(['payload' => array_replace(payload(), ['readiness' => 'none'])]);
    tick($g);
    eq(Run::query()->first()->result['readiness'], 'process_running');
    eq(AnnouncementDelivery::query()->where('event', 'wiping')->count(), 2);
    eq(AnnouncementDelivery::query()->where('event', 'wiped')->count(), 2);
    foreach (Bus::dispatched(SendAnnouncement::class) as $job) {
        eq($job->queue, 'rust-wiper-notifications');
    }
    eq(Http::recorded()->count(), 0);
});
test('wipe failure and worker failure callbacks share one notification occurrence', function () {
    $config = announcementFixture();
    $config['rules'][0]['event'] = 'failed';
    $config['rules'][0]['minutes'] = null;
    (new AnnouncementsController)->save(adminRequest($config), Server::query()->findOrFail(1));
    $g = new FakeGateway;
    $g->stopWorks = false;
    app()->instance(Gateway::class, $g);
    $run = Run::query()->create(['server_id' => 1, 'label' => 'Failed wipe', 'payload' => payload(), 'timezone' => 'UTC', 'due_at' => now('UTC'), 'status' => 'queued']);
    $job = new ExecuteWipe($run->id);
    $job->handle(new WipeEngine($g));
    $job->failed(new RuntimeException('Worker failed'));
    eq($run->fresh()->status, 'failed');
    eq(AnnouncementDelivery::query()->where('event', 'failed')->count(), 2);
    eq(Http::recorded()->count(), 0);
});
test('legacy settings become editable modular presets without returning the saved URL', function () {
    announcementFixture();
    $settings = Settings::forServer(1);
    $settings->announcement_config = null;
    $settings->discord_webhook = 'https://discord.com/api/webhooks/789/legacy-token';
    $settings->options = ['discord_events' => ['upcoming', 'completed', 'ready', 'failed'], 'discord_minutes' => 10, 'discord_template' => 'Legacy {label}'];
    $settings->save();
    $data = AnnouncementConfig::publicData($settings);
    eq(count($data['rules']), 3);
    eq($data['webhooks'][0]['url'], '');
    eq($data['presets'][0]['content'], 'Legacy {label}');
});
test('announcement settings and worker probes require configuration permission',function () {
    $controller = new AnnouncementsController;
    $server = Server::query()->findOrFail(1);
    raises(fn () => $controller->index(request([],false),$server));
    raises(fn () => $controller->save(request([],false),$server));
    raises(fn () => $controller->preview(request([],false),$server));
    raises(fn () => $controller->probe(request([],false),$server));
});
Carbon::setTestNow();
