<?php

declare(strict_types=1);

namespace RustServerWiper\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\HealthProbe;
use RustServerWiper\Models\AnnouncementDelivery;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\Wipe;
use RustServerWiper\Services\AnnouncementConfig;
use RustServerWiper\Services\AnnouncementMessage;
use RustServerWiper\Services\Announcements;
use RustServerWiper\Services\Permissions;

final class AnnouncementsController
{
    public function index(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');

        return new JsonResponse(['configuration' => AnnouncementConfig::publicData(Settings::forServer($server->id)),
            'schedules' => Wipe::query()->where('server_id', $server->id)->get(['id', 'label', 'next_run_at']),
            'deliveries' => AnnouncementDelivery::query()->where('server_id', $server->id)->latest('id')->limit(50)->get(['id', 'event', 'preset_name', 'destination_name', 'status', 'message', 'created_at'])]);
    }

    public function save(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        $settings = DB::transaction(function () use ($r, $server) {
            Server::query()->whereKey($server->id)->lockForUpdate()->firstOrFail();
            $settings = Settings::forServer($server->id);
            $settings->announcement_config = AnnouncementConfig::validate($r->all(), $settings);
            $settings->save();

            return $settings;
        });

        return new JsonResponse(['configuration' => AnnouncementConfig::publicData($settings)]);
    }

    public function preview(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        $r->validate(['preset' => 'required|array', 'event' => 'required|in:before,wiping,wiped,failed', 'minutes' => 'sometimes|integer|min:1|max:10080']);
        $preset = AnnouncementConfig::preset($r->input('preset'));
        $epoch = now('UTC')->addMinutes($r->integer('minutes', 30))->timestamp;
        $values = Announcements::context($server->id, ['event' => $r->input('event'), 'label' => 'Example wipe', 'message' => 'Example event message. This is a preview; nothing was sent.', 'minutes' => $r->integer('minutes', 30), 'epoch' => $epoch, 'time' => gmdate('Y-m-d H:i', $epoch).' UTC']);

        return new JsonResponse(['payload' => AnnouncementMessage::render($preset, $values)]);
    }

    public function probe(Request $r, Server $server): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        $id = (string) Str::uuid();
        HealthProbe::dispatch($server->id, $id, 'rust-wiper-notifications');

        return new JsonResponse(['probe_id' => $id, 'asynchronous' => config('queue.default') !== 'sync' && ! in_array(config('cache.default'), ['array', 'null'], true)]);
    }

    public function probeStatus(Request $r, Server $server, string $probeId): JsonResponse
    {
        Permissions::authorize($r->user(), $server, 'configure');
        abort_unless(Str::isUuid($probeId), 404);

        return new JsonResponse(['acknowledged' => Cache::has('rust-server-wiper:probe:'.$server->id.':'.$probeId)]);
    }
}
