# Version 2.3.0-beta.1

This prerelease adds modular Discord webhook destinations, reusable message presets, event rules and timestamp copying. It includes the [2.2 features](FEATURES-2.2.md) and retains the [2.1 queue, backup and permission requirements](FEATURES-2.1.md).

## Install and delivery worker

Install `rust-server-wiper-2.3.0-beta.1.pteroext` with the native extension installer and restart queue workers. The migration adds encrypted announcement configuration and delivery metadata without deleting schedules, history or the old Discord connection.

**Run an additional worker in a separate process for announcement delivery:**

```sh
cd /var/www/pterodactyl
php artisan queue:work --queue=rust-wiper-notifications --sleep=1 --tries=20 --timeout=30
```

Manage that process with the same process manager used for the panel's normal worker. Keep the existing `standard` worker and minute scheduler. Both workers and the web app must use the same asynchronous queue connection and persistent shared cache. A single worker listening to both queues can block announcements while executing a wipe; use a separate process.

After setup, open **Settings → Discord announcements → Check delivery worker**. This sends a harmless acknowledgement job, not a Discord message. The UI polls for 30 seconds and reports an unavailable worker. A synchronous test queue is never presented as proof of a separate worker.

## Build your announcements

1. **Webhooks:** add a name and Discord webhook URL for each channel. URLs are encrypted and never returned to the browser. Blank URL fields preserve existing secrets. Destinations can be paused individually.
2. **Message presets:** start with a reminder, shutdown, completion, failure or custom message. Text and embeds are independently optional; at least one must contain visible content. Reuse a preset across events and channels.
3. **Announcement rules:** choose an event, preset and one or more destinations. Apply rules to all wipes or one saved schedule. Add separate rules for multiple messages at an event or reminders at different offsets. Save changes together.

The embed editor supports title, description, title link, color, author and icon, image, thumbnail, footer and icon, event timestamp, and inline/non-inline fields. Discord limits are validated: 2,000 message characters, ten embeds, 25 fields per embed and 6,000 combined embed-text characters. Expansion is validated again at delivery. Empty unused embeds are omitted.

**Preview message** renders example values in the app without posting a webhook. Discord performs final Markdown and timestamp rendering. Role IDs are configured per preset, prefixed to the text and explicitly allowed. User mentions are disabled. `@everyone` and `@here` require an explicit opt-in and must be written in the content.

Configuration is bounded to 100 destinations, 200 presets, 500 rules and 2 MB per server to prevent unbounded queue/scheduler work. There is no one-rule-per-event restriction. Revision checks reject stale saves from concurrent sessions.

## Events and delivery

| Event | Trigger |
| --- | --- |
| Before the wipe | Configured 1–10,080 minutes before a scheduled occurrence; multiple offsets/presets are supported. |
| Wiping / server going down | After preflight and player policies pass, just before graceful stop. Already-stopped servers also emit this wipe-start event. |
| Wiped / server running | First observed Wings `running` state after deletion and a start request. Start acceptance alone is insufficient. Rust may still be loading. |
| Wipe or startup failed | Execution or worker failure, missed execution, stale-worker recovery, or startup/readiness timeout. |

Immediate wipes emit lifecycle events but have no reminder period. Missed reminder minutes are not replayed after scheduler downtime. A running announcement can be followed by failure if a requested Rust/RCON readiness check later times out.

Delivery is asynchronous, so event and delivery times can differ. Per-webhook locks serialize requests. Explicit Discord 429 rejections can be retried with a bounded delay. Ambiguous network errors and other failed responses are recorded without automatic replay because a lost acknowledgement can cause duplicate messages. Each rule/destination/occurrence has an independent receipt. Notification failure never fails or replays a wipe.

Reminders expire after five minutes or at wipe time, whichever comes first. Shutdown messages expire after five minutes and are cancelled if their run has finished. Completion/failure messages expire after one day. Queued messages recheck the extension, master switch, rule and destination. Already-sent or in-flight messages cannot be recalled.

**Recent announcement deliveries** lists preset, destination, event, status and recovery instructions. Queued payloads are encrypted. Webhook URLs are omitted from queue arguments, API responses and error messages.

## Tokens

| Token | Value |
| --- | --- |
| `{server}` | Panel server name |
| `{label}` | Wipe/schedule name |
| `{event}` | `before`, `wiping`, `wiped` or `failed` |
| `{message}` | Event result or phase description |
| `{minutes}` | Reminder lead time; blank for other event types |
| `{time}` | Wipe time in the schedule timezone |
| `{epoch}` | Wipe time as Unix seconds |
| `{timestamp}` | Wipe time as Discord full date/time |
| `{relative}` | Wipe time as Discord relative time |
| `{iso_time}` | ISO 8601 event time for the embed timestamp |

## Copy Discord timestamps

Next-run cells and upcoming occurrences show **Epoch** with Unix seconds. Click it, then choose a format to copy immediately. The menu includes raw epoch, Discord default and all nine documented styles: `t`, `T`, `d`, `D`, `f`, `F`, `s`, `S`, `R`. Examples use your timezone; Discord adapts pasted timestamps to each viewer. Keyboard navigation is supported, and the menu escapes scrollable table containers. Clipboard failures provide a manual-copy value.

References: [Discord webhooks](https://discord.com/developers/docs/resources/webhook#execute-webhook), [embed limits](https://discord.com/developers/docs/resources/message#embed-object-embed-limits), [timestamp styles](https://discord.com/developers/docs/reference#message-formatting-timestamp-styles).

## Existing installations and verification

Legacy Discord settings keep their existing delivery behavior until **Save announcements** is chosen. The editor presents the old webhook and template as reusable entries; old completion/ready selections become one running rule to avoid duplicate completion posts. Configure/check the new delivery worker before saving. WebRCON and its saved-password behavior remain separate.

Announcements are excluded from configuration export/import alongside connection settings. Local tests use HTTP mocks and a fake daemon; browser checks use a labelled fixture. No real Discord messages were sent. Actual queue delivery, Discord rendering/rate limits and live Rust/Wings timing require a staging check before a stable release.
