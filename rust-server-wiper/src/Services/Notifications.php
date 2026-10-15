<?php

declare(strict_types=1);

namespace RustServerWiper\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RustServerWiper\Models\Settings;
use Throwable;

final class Notifications
{
    public static function validWebhook(string $url): bool
    {
        return (bool) preg_match('~^https://discord\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+$~D', $url);
    }

    public function send(int $serverId, string $event, string $key, array $values): void
    {
        try {
            $this->deliver($serverId, $event, $key, $values);
        } catch (Throwable) {
            Log::warning('Rust wiper notification failed.', ['server_id' => $serverId, 'event' => $event]);
        }
    }

    private function deliver(int $serverId, string $event, string $key, array $values): void
    {
        $settings = Settings::forServer($serverId);
        if ($settings->announcement_config !== null) {
            return;
        }
        $options = $settings->options ?? [];
        if (! $settings->discord_webhook || ! in_array($event, $options['discord_events'] ?? ['upcoming', 'completed', 'ready', 'failed'], true)) {
            return;
        }
        if (! self::validWebhook($settings->discord_webhook)) {
            return;
        }
        // At-most-once delivery: a lost HTTP acknowledgement never creates a second mention.
        if (! DB::table('ext_rust_server_wiper_notifications')->insertOrIgnore(['server_id' => $serverId, 'event_key' => $key, 'status' => 'sending', 'created_at' => now('UTC'), 'updated_at' => now('UTC')])) {
            return;
        }
        try {
            $template = $options['discord_template'] ?? '{label}: {event}. {message}';
            $replace = [];
            foreach (['label', 'event', 'message', 'time', 'server'] as $name) {
                $replace['{'.$name.'}'] = (string) ($values[$name] ?? ($name === 'event' ? $event : ''));
            }
            $text = mb_substr(strtr($template, $replace), 0, 1900);
            $role = $options['discord_role'] ?? '';
            if ($role !== '') {
                $text = '<@&'.$role.'> '.$text;
            }
            $response = Http::connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false])->post($settings->discord_webhook, ['content' => $text, 'allowed_mentions' => ['parse' => [], 'roles' => $role === '' ? [] : [$role]]]);
            DB::table('ext_rust_server_wiper_notifications')->where('event_key', $key)->update(['status' => $response->successful() ? 'sent' : 'failed', 'message' => $response->successful() ? null : 'Discord returned HTTP '.$response->status(), 'updated_at' => now('UTC')]);
        } catch (Throwable) {
            // Do not log exception text that could contain a secret webhook URL.
            DB::table('ext_rust_server_wiper_notifications')->where('event_key', $key)->update(['status' => 'failed', 'message' => 'Discord notification failed. Check the webhook and network.', 'updated_at' => now('UTC')]);
        }
    }
}
