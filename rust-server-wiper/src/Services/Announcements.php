<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Models\Server;
use RustServerWiper\Jobs\SendAnnouncement;
use RustServerWiper\Models\AnnouncementDelivery;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Models\Wipe;
use Throwable;

final class Announcements
{
    public static function context(int $serverId, array $values): array
    {
        $epoch = (int) ($values['epoch'] ?? now('UTC')->timestamp);

        return [...$values, 'epoch' => (string) $epoch, 'timestamp' => '<t:'.$epoch.':F>', 'relative' => '<t:'.$epoch.':R>', 'iso_time' => now('UTC')->toIso8601String(), 'server' => Server::query()->find($serverId)?->name ?? 'Rust server'];
    }

    public function wantsRunning(int $serverId, ?int $wipeId = null): bool
    {
        $config = Settings::forServer($serverId)->announcement_config;
        if (empty($config['enabled'])) {
            return false;
        }
        $hooks = array_column($config['webhooks'], null, 'id');
        foreach ($config['rules'] as $rule) {
            if (! $rule['enabled'] || $rule['event'] !== 'wiped' || ($rule['wipe_id'] && $rule['wipe_id'] !== $wipeId)) {
                continue;
            }
            foreach ($rule['webhook_ids'] as $id) {
                if (! empty($hooks[$id]['enabled'])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function run(Run $run, string $event): void
    {
        $this->emit($run->server_id, $event, 'run:'.$run->id.':'.$event, ['run_id' => $run->id, 'wipe_id' => $run->wipe_id, 'label' => $run->label, 'message' => $run->message ?? '', 'epoch' => $run->due_at->timestamp, 'time' => $run->due_at->setTimezone($run->timezone)->format('Y-m-d H:i T')]);
    }

    public function reminder(Wipe $wipe, CarbonInterface $now): void
    {
        $minutes = (int) floor(($wipe->next_run_at->timestamp - $now->timestamp) / 60);
        $this->emit($wipe->server_id, 'before', 'wipe:'.$wipe->id.':'.$wipe->next_run_at->timestamp.':before', ['wipe_id' => $wipe->id, 'label' => $wipe->label, 'minutes' => $minutes, 'message' => 'Wipe in '.$minutes.' minutes.', 'epoch' => $wipe->next_run_at->timestamp, 'time' => $wipe->next_run_at->setTimezone($wipe->timezone)->format('Y-m-d H:i T')]);
    }

    /** Notification failures must never abort or replay a wipe. */
    public function emit(int $serverId, string $event, string $occurrence, array $values): void
    {
        try {
            $settings = Settings::forServer($serverId);
            if ($settings->announcement_config === null) {
                return;
            } // Legacy delivery stays active until the new configuration is saved.
            $config = $settings->announcement_config;
            if (! $config['enabled']) {
                return;
            }
            $presets = array_column($config['presets'], null, 'id');
            $hooks = array_column($config['webhooks'], null, 'id');
            $values = self::context($serverId, [...$values, 'event' => $event]);
            foreach ($config['rules'] as $rule) {
                if (! $rule['enabled'] || $rule['event'] !== $event || ($rule['wipe_id'] && $rule['wipe_id'] !== ($values['wipe_id'] ?? null))) {
                    continue;
                }
                if ($event === 'before' && $rule['minutes'] !== ($values['minutes'] ?? null)) {
                    continue;
                }
                foreach ($rule['webhook_ids'] as $hookId) {
                    if (empty($hooks[$hookId]['enabled']) || ! isset($presets[$rule['preset_id']])) {
                        continue;
                    }
                    $key = 'announcement:'.hash('sha256', $serverId.'|'.$occurrence.'|'.$rule['id'].'|'.$hookId);
                    if (! DB::table('ext_rust_server_wiper_notifications')->insertOrIgnore(['server_id' => $serverId, 'event_key' => $key, 'status' => 'preparing', 'rule_id' => $rule['id'], 'destination_id' => $hookId, 'preset_name' => $presets[$rule['preset_id']]['name'], 'destination_name' => $hooks[$hookId]['name'], 'event' => $event, 'run_id' => $values['run_id'] ?? null, 'expires_at' => $event === 'before' ? now('UTC')->addSeconds(min(300, max(0, (int) $values['epoch'] - now('UTC')->timestamp))) : ($event === 'wiping' ? now('UTC')->addMinutes(5) : now('UTC')->addDay()), 'created_at' => now('UTC'), 'updated_at' => now('UTC')])) {
                        continue;
                    }
                    $delivery = AnnouncementDelivery::query()->where('event_key', $key)->firstOrFail();
                    try {
                        $payload = AnnouncementMessage::render($presets[$rule['preset_id']], $values);
                        $delivery->update(['delivery_payload' => $payload, 'status' => 'queued']);
                        SendAnnouncement::dispatch($delivery->id);
                    } catch (Throwable) {
                        $delivery->update(['status' => 'failed', 'message' => 'Could not prepare or queue the message. Check template lengths, embed content and the notification queue.']);
                    }
                }
            }
        } catch (Throwable) {
            Log::warning('Rust wiper announcement scheduling failed.', ['server_id' => $serverId, 'event' => $event]);
        }
    }
}
