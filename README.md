# Rust Server Wiper

Schedule and manage Rust server wipes from Pterodactyl. Review the files, choose the next map, check server health, and follow each wipe through backups, shutdown, deletion and startup in one interface.

This is a **native Pterodactyl 2 extension**. Installation uses the panel's extension manager; no panel source patches, Blueprint installation or panel frontend rebuild is required.

**Current package: 2.3.1-beta.1.** Locally verified prerelease; live Rust/Wings and Discord delivery checks remain open. Use a disposable server before enabling production schedules.

[Download the extension](rust-server-wiper-2.3.1-beta.1.pteroext) · [Installation and operator guide](rust-server-wiper/README.md) · [Latest changes](rust-server-wiper/FEATURES-2.3.1.md) · [Verification results](rust-server-wiper/resources/docs/TEST-RESULTS.md)

## What you can do

| Area | Features |
| --- | --- |
| Guided wipe setup | Four steps for wipe scope, next map, timing and review; exact file previews and explicit acknowledgement for immediate wipes. |
| Scheduling | One-time, monthly weekday, weekly and anchored 14-day repeats; timezone dropdown, upcoming occurrences, pause and skip-next controls. |
| Maps and presets | Reusable wipe presets, identity suggestions, procedural settings, custom map URLs, ordered/weighted map rotation and URL checks. |
| Health and safeguards | Read-only preflight checks, queue probes, optional player policies, confirmed pre-wipe backups, maintenance protection and explicit restore actions. |
| Discord announcements | Multiple webhook destinations, reusable text/embed presets, multiple reminders and rules for shutdown, observed running and failure. |
| Shareable times | Epoch values with instant copying for Discord timestamp formats, including relative time. |
| Configuration transfer | Review JSON imports, adapt identity paths and create imported schedules paused; exports omit connection secrets and announcements. |
| Operations | Run reports, backup history, permissions and administrator controls for multiple servers. |

## Compatibility

| Component | Requirement / verified scope |
| --- | --- |
| Pterodactyl | Tested on **2.0.0-beta.1**, commit `65913c6aac5367a35f48b4448f027354b9b2a577`. Panel 1.x is unsupported. |
| Manifest range | Panel `>=2.0.0-beta.1 <2.1.0`; later builds in that range have not been verified. |
| PHP | `>=8.3 <9.0`, with the panel's normal dependencies. |
| Rust egg | Rust discovery plus `WORLD_SEED` and `WORLD_SIZE` variables; custom identity directories are supported. |
| Runtime | Minute scheduler, asynchronous `standard` queue worker, and persistent shared cache with atomic locks. Discord announcements require a separate delivery worker. |
| Build SDK | Bundled upstream SDK **2.0.0-beta.6**; only needed for development. |

See [the operator guide](rust-server-wiper/README.md#install) for custom egg discovery, permissions and queue configuration.

## Install

1. Back up the panel database. Disable the old auto-wiper cron/job if upgrading from the original addon.
2. Keep the panel's minute scheduler and `standard` queue worker running. Set the queue reservation above the wipe job's **1,200-second timeout**, for example `REDIS_QUEUE_RETRY_AFTER=1260`, then rebuild the panel configuration cache and restart workers. All processes must share an asynchronous queue and persistent cache. See [queue setup](rust-server-wiper/README.md#install) for other drivers.
3. Download the [2.3.1-beta.1 package](rust-server-wiper-2.3.1-beta.1.pteroext) and verify its [SHA-256 checksum](rust-server-wiper-2.3.1-beta.1.sha256). Upload it under **Admin → Extensions → Install** and enable it, or use the panel CLI:

   ```sh
   cd /var/www/pterodactyl
   php artisan p:extension:install /path/to/rust-server-wiper-2.3.1-beta.1.pteroext --enable
   ```

4. For Discord announcements, configure a **separate supervised process**:

   ```sh
   php artisan queue:work --queue=rust-wiper-notifications --sleep=1 --tries=20 --timeout=30
   ```

   Keep the normal worker running too. Open **Settings → Discord announcements → Check delivery worker** to verify acknowledgement without sending a Discord message.
5. Open **Rust Wipes** on a Rust server. Review the identity directory, file preview, timezone and health results before testing a wipe on a disposable server.

Read the [complete installation, upgrade and legacy-import instructions](rust-server-wiper/README.md) before deploying. The package includes compiled assets and migrations; installing it does not require Node or a frontend build.

## A typical workflow

Open **Schedules** and create a wipe. Choose the exact files or a preset, set the next map, pick the date and timezone, then review the health check and summary. New one-time dates use your browser timezone by default; schedules retain their own timezone.

Use **Map library** for rotation maps, **Activity & backups** for reports and restores, and **Import / export** to move configuration between servers. In **Settings**, add named webhooks, compose message presets, and connect them through announcement rules. Multiple rules can target the same event or different reminder offsets.

The **wiped / server running** event requires an observed Wings running state after deletion and startup. Rust may still be loading; optional readiness checks provide a separate result. Failed or interrupted destructive runs are not automatically replayed, and partial changes require operator review.

## Documentation

| Guide | Contents |
| --- | --- |
| [Operator guide](rust-server-wiper/README.md) | Installation, upgrades, permissions, paths, execution, recovery and troubleshooting. |
| [2.3 feature guide](rust-server-wiper/FEATURES-2.3.md) | Modular webhooks, rich embeds, event timing, worker setup, delivery behavior and timestamp copying. |
| [2.2 feature guide](rust-server-wiper/FEATURES-2.2.md) | Health checks, configuration import/export, timezones and weekly/14-day schedules. |
| [2.1 feature guide](rust-server-wiper/FEATURES-2.1.md) | Backups, permissions, maintenance, readiness, player policies and multi-server administration. |
| [Changelog](rust-server-wiper/resources/docs/CHANGELOG.md) | Version history. |
| [Verification results](rust-server-wiper/resources/docs/TEST-RESULTS.md) | Test counts, package/upgrade checks and limits of local verification. |
| [Roadmap](ROADMAP.md) | Implementation checklist and remaining live staging checks. |

## Development

From the extension directory:

```sh
cd rust-server-wiper
npm ci --legacy-peer-deps
npm run typecheck
npm test
npm run build
php tests/run.php /path/to/a/disposable/pterodactyl-checkout
```

The PHP suite needs an uncached disposable panel checkout with Composer dependencies and SQLite support. It uses an isolated database and simulated daemon. Never point these tests at a production panel. See [development and packaging](rust-server-wiper/README.md#development-and-verification) for the native extension doctor and pack commands.

Current local results: **156 PHP tests and 27 React tests passed**, along with type checking, production build, desktop/mobile browser checks, native installation and upgrades from 2.0 and 2.2. Live backup/restore, Rust startup, asynchronous delivery and Discord rendering remain staging requirements.

## Provenance

Converted from the supplied UndercoverNL Rust Server Wiper addon. This repository does not grant new redistribution rights for the original proprietary addon. The bundled Pterodactyl build SDK is MIT licensed; see [SDK-LICENSE.txt](rust-server-wiper/SDK-LICENSE.txt). Original purchased addon files and local testing tools are excluded from version control.
