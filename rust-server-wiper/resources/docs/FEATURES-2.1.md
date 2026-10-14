# Version 2.1.0-beta.1

All twelve planned features are implemented on `feature/v2.1.0`. This is a beta pending live Rust/Wings verification. The `v2.0.0` tag remains the baseline. Existing schedules, maps and history survive the upgrade.

## Upgrade requirements

The worker timeout is now **1,200 seconds**. Set `REDIS_QUEUE_RETRY_AFTER=1260` (or `DB_QUEUE_RETRY_AFTER=1260`/`BEANSTALKD_QUEUE_RETRY_AFTER=1260`, or SQS visibility above 1,200 seconds), then run `php artisan config:cache` and `php artisan queue:restart`. Web requests, scheduler and workers must share persistent atomic locks, normally Redis. Keep the minute scheduler and `standard` worker running.

Back up the panel database, install `rust-server-wiper-2.1.0-beta.1.pteroext` with the native installer, and reload. Add `--replace` for a noninteractive CLI replacement. New forms default to a backup and a Wings process check. Existing payloads keep their previous behaviour until edited. Test a disposable server before enabling production schedules.

## Backups and restoration

**Create a locked full-server backup before deleting** uses the panel's native Wings/S3 adapter after a confirmed graceful stop. It includes the selected wipe files and configuration. A comment-only ignore list deliberately overrides Wings' `.pteroignore` fallback without excluding files, as described by the [Wings implementation](https://github.com/pterodactyl/wings/blob/develop/server/backup.go). External databases and external mounted data are not claimed as covered by the filesystem backup.

Deletion waits for Wings' successful callback. Failure or timeout leaves the server stopped and deletes nothing. Backup confirmation timeout is configurable from 30 to 600 seconds. Normal quotas apply; the extension does not rotate away existing backups. Backups are locked: review and unlock/delete older backups in the panel when appropriate.

**Restore full backup** replaces **all current server files**, and restores the startup values changed by that wipe. Its confirmation explicitly names that scope. Restoration has its own permission, stopped-state checks and history, and leaves the server stopped for review. Confirmation waits up to ten minutes. A timeout after requesting restore retains maintenance until expiry/recovery, because Wings may still be working. Failed restores are never automatically replayed.

Previous startup values are encrypted and hidden from API responses and reports. Browser wipe/restore requests use UUID request IDs to prevent repeating a completed destructive operation after a lost response. API integrations should supply `request_uuid` for immediate wipes; it is required for restore requests.

## Permissions

| Permission | Allows |
| --- | --- |
| `ext.rust-server-wiper.view` | Schedules, maps, calendar, presets, maintenance and reports |
| `ext.rust-server-wiper.schedule` | Scheduled destructive wipes, presets, map rules and file previews |
| `ext.rust-server-wiper.execute` | File previews and immediate destructive wipes |
| `ext.rust-server-wiper.restore` | Full-server backup restoration |
| `ext.rust-server-wiper.configure` | Encrypted Discord/RCON settings and expired-maintenance recovery |

Specific action grants imply view access. Legacy `ext.rust-server-wiper.manage` continues to allow view, schedule and immediate execution; it does **not** grant restore or secret configuration. Owners and root administrators pass normal checks. The admin batch screen is root-administrator only.

## Presets, identity and map rules

**Apply preset** offers a map wipe, a full wipe with blueprints, and saved server presets. **Save as preset**, **Update preset** and **Delete preset** manage reusable file/startup/execution configurations. For plugin data, select its paths explicitly and save that configuration. Applying a preset exposes its patterns for review; it never executes a wipe.

Identity detection checks `+server.identity`, including startup-variable substitution, then `SERVER_IDENTITY`/`IDENTITY`. It shows the detected source and offers **Use detected directory**. Manual overrides remain available. Unknown identities are reported rather than guessed.

Map rotation supports random, ordered and weighted policies, editable saved-map order/weight, and exclusion of up to 20 prior choices. When the pool is too small, recent exclusions are relaxed while the current map is avoided where possible. A one-map pool works. Reports record selection and fallback.

Custom map URLs are checked before stopping using bounded public HTTP(S) HEAD requests, pinned resolved IP addresses, and at most three validated redirects. Private/reserved destinations and embedded credentials are rejected. PHP curl is required. Hosts that reject HEAD will fail verification. Rust later downloads the map through its own network; a successful URL check does not guarantee game loading.

## Calendar, players and readiness

**Upcoming wipes** previews future occurrences in each schedule's timezone, lists skipped fifth-weekday/DST dates and flags schedules less than 20 minutes apart. It uses the same recurrence calculation as execution.

**Wait until the server is empty** is opt-in. Rust WebRCON supplies the player count. Deferred runs retry every five minutes, with a maximum deferral of 5–1,440 minutes. Choose cancel/proceed at the deadline and cancel/defer/proceed for an unavailable count. Defaults are defer when unknown and cancel at the deadline. Offline servers count as empty. History explains the policy outcome.

Startup checks support start-signal only, Wings process running, or Rust responding through WebRCON. Checks wait up to five minutes. History distinguishes start accepted, process running, Rust ready and readiness timed out. A timeout becomes `startup_failed` after the file wipe, and never repeats deletion.

Configure WebRCON under **Discord notifications and WebRCON settings**. The host comes from the allocation, falling back to the node address when unspecified. Defaults use `RCON_PORT` and `RCON_PASS`/`RCON_PASSWORD`; an encrypted password override and TLS proxy option are available. Rust must enable WebRCON and the worker must reach its port. **Check saved RCON configuration** reads players using saved values. Protocol reference: [Facepunch/webrcon](https://github.com/Facepunch/webrcon).

## Discord, maintenance and reports

Configure an encrypted `https://discord.com/api/webhooks/...` URL, upcoming/completed/ready/failed events, announcement lead time, message template and optional role ID. Tokens: `{label}`, `{event}`, `{message}`, `{time}`, `{server}`. Only the configured role may be mentioned. Blank secret fields keep values; removal checkboxes clear them.

Notifications are at most once per event. Failures are recorded in `ext_rust_server_wiper_notifications` separately from the wipe result. Lost acknowledgements are not retried because that could duplicate mentions. No live Discord messages were sent during testing.

Maintenance blocks supported panel power/console, file mutation, startup/egg, reinstall, mount and backup-restore actions. Extension-owned actions bypass guards only inside their internal operation scope. Reading remains available. Leases expire after 25 minutes if a worker dies. **Recover expired maintenance** requires configure permission and refuses a recent active operation or a server still restoring a backup.

Maintenance does not revoke SFTP sessions, previously issued direct Wings upload URLs, or operations outside the panel. Avoid those concurrent changes. Disabling the extension disables its action wrappers. Normal failed wipes release maintenance and leave the stopped server available for recovery.

Reports include initiator, last schedule editor, phase timeline, timestamps/duration, seed/map, removed entries and backup reference. **Download report** exports JSON, omits encrypted recovery values and redacts credential keys and URL queries. Native file/startup/power actions also supply their normal activity entries.

## Multi-server administration

Open **Admin → Rust Wipes**, select up to 50 servers, choose a saved preset, a first one-time date/time and a stagger interval. Review each target's files and identity before confirmation. Identity-relative paths adapt to detected target directories. Unknown identities fail unless the explicit preset-directory fallback is enabled. Results are independent, and batch UUIDs prevent duplicate schedules on a repeated request. Recent results across servers are shown. The batch UI creates one-time schedules; repeat rules remain editable per server.

## Verification

See [TEST-RESULTS.md](TEST-RESULTS.md). The backend suite uses real panel/Laravel code with isolated SQLite, HTTP mocks, a simulated daemon, and a local mock WebRCON server. Upgrade checks use separate PHP processes to verify baseline data preservation and fresh provider loading. Browser checks use a clearly labelled native-shell approximation.

```sh
php tests/installation.php /path/to/disposable/panel /path/to/new.pteroext
php tests/upgrade.php /path/to/disposable/panel /path/to/baseline.pteroext /path/to/new.pteroext
```

Use a disposable, uncached panel checkout with Composer dependencies and PHP SQLite, never production. No live Rust/Wings host was connected. Actual backup/restore, Rust readiness, player counts, notification delivery, filesystem mutations and interaction with other extensions still need staging verification before stable release.
