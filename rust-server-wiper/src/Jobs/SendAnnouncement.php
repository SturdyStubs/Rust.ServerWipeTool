<?php

declare(strict_types=1);

namespace RustServerWiper\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RustServerWiper\Models\AnnouncementDelivery;
use RustServerWiper\Models\Run;
use RustServerWiper\Models\Settings;
use RustServerWiper\Services\Availability;
use RustServerWiper\Services\Notifications;
use Throwable;

final class SendAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public function __construct(public int $deliveryId)
    {
        $this->onQueue('rust-wiper-notifications');
    }

    public function handle(): void
    {
        $delivery = AnnouncementDelivery::query()->find($this->deliveryId);
        if (! $delivery || $delivery->status !== 'queued') {
            return;
        }
        if (($delivery->expires_at && $delivery->expires_at <= now('UTC')) || ($delivery->event === 'wiping' && $delivery->run_id && Run::query()->whereKey($delivery->run_id)->value('status') !== 'running')) {
            $delivery->update(['status' => 'cancelled', 'message' => 'This announcement is no longer timely. It was not sent.']);

            return;
        }
        $config = Settings::forServer($delivery->server_id)->announcement_config;
        $hook = collect($config['webhooks'] ?? [])->firstWhere('id', $delivery->destination_id);
        $rule = collect($config['rules'] ?? [])->firstWhere('id', $delivery->rule_id);
        if (! Availability::enabled() || empty($config['enabled']) || empty($hook['enabled']) || empty($rule['enabled']) || ! in_array($delivery->destination_id, $rule['webhook_ids'] ?? [], true)) {
            $delivery->update(['status' => 'cancelled', 'message' => 'The extension, rule or destination was disabled or removed before delivery.']);

            return;
        }
        if (! Notifications::validWebhook($hook['url'])) {
            $delivery->update(['status' => 'failed', 'message' => 'The destination is not a valid Discord webhook.']);

            return;
        }
        $bucket = 'rust-server-wiper:discord:'.hash('sha256', $hook['url']);
        $wait = (int) Cache::get($bucket.':retry', 0) - now('UTC')->timestamp;
        if ($wait > 0) {
            $this->release(min(60, $wait));

            return;
        }
        $lock = Cache::lock($bucket, 25);
        if (! $lock->get()) {
            $this->release(2);

            return;
        }
        try {
            if (AnnouncementDelivery::query()->whereKey($delivery->id)->where('status', 'queued')->update(['status' => 'sending']) !== 1) {
                return;
            }
            $delivery->refresh();
            // wait=true asks Discord to confirm that it stored the message. Never follow redirects with a webhook token.
            $response = Http::connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false])->post($hook['url'].'?wait=true', $delivery->delivery_payload);
            if ($response->status() === 429) {
                // A confirmed rate-limit rejection was not sent; unlike an ambiguous connection failure it is safe to retry.
                $retry = max(1, min(3600, (int) ceil((float) ($response->json('retry_after') ?? 5))));
                Cache::put($bucket.':retry', now('UTC')->timestamp + $retry, $retry + 5);
                $delivery->update(['status' => 'queued', 'message' => 'Discord rate limited this destination; waiting before retrying.']);
                $this->release(min(60, $retry));

                return;
            }
            $delivery->update(['status' => $response->successful() ? 'sent' : 'failed', 'message' => $response->successful() ? null : 'Discord returned HTTP '.$response->status().'. Check the destination and message content.']);
        } catch (Throwable) {
            $delivery->update(['status' => 'failed', 'message' => 'Delivery could not be confirmed. Check Discord before sending again; automatic retries are disabled for ambiguous responses.']);
        } finally {
            $lock->release();
        }
    }

    public function failed(?Throwable $e): void
    {
        AnnouncementDelivery::query()->whereKey($this->deliveryId)->whereIn('status', ['queued', 'sending'])->update(['status' => 'failed', 'message' => 'The delivery worker stopped or exhausted its retries. Check Discord before sending again.']);
    }
}
