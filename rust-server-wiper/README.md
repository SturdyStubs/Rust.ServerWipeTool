# Rust Server Wiper 2.1.0-beta.1

Read [FEATURES-2.1.md](FEATURES-2.1.md) for the new features, permissions, defaults and upgrade requirements. This is a beta pending live server verification.

Native extension converted from the supplied UndercoverNL Rust Server Wiper addon. No panel source patches, Blueprint installation, or panel frontend rebuild is required. Original files in `Manual` and `Blueprint` are preserved.

## Install

Tested against **Pterodactyl 2.0.0-beta.1**, commit `65913c6aac5367a35f48b4448f027354b9b2a577`. Its bundled SDK is **2.0.0-beta.6**. Later beta versions are not covered by the test results; run the panel's extension doctor before upgrading. Manifest requirements reject Panel 1.x.

1. Disable the old auto-wiper scheduler/job before enabling this extension. Remove any separate cron invocation of `p:server:wipe` and the old `RustWipeCommand` scheduler registration if they survived the panel upgrade. Do not run both implementations.
2. In the panel `.env`, set the queue reservation to longer than the wipe worker's 1,200-second timeout. For the standard Redis queue:

   ```dotenv
   REDIS_QUEUE_RETRY_AFTER=1260
   ```

   For a database queue use `DB_QUEUE_RETRY_AFTER=1260`; for Beanstalkd use `BEANSTALKD_QUEUE_RETRY_AFTER=1260`. For SQS set a visibility timeout above 1,200 seconds. The web app, scheduler and workers must share a persistent cache with atomic locks, normally Redis. `array` cache is for tests only. Keep the panel's normal minute scheduler and `standard` queue worker running.

   ```sh
   cd /var/www/pterodactyl
   php artisan config:cache
   php artisan queue:restart
   ```

3. Upload `rust-server-wiper-2.1.0-beta.1.pteroext` in **Admin → Extensions → Install** and select **Enable**. Alternatively:

   ```sh
   php artisan p:extension:install /path/to/rust-server-wiper-2.1.0-beta.1.pteroext --enable
   chown -R www-data:www-data extensions public/assets/extensions
   ```

4. Reload the panel. Open **Rust Wipes** on a Rust server. The tab appears for eggs tagged `rust`, startup commands containing Rust/RustDedicated, or exposed `WORLD_SEED` variables. Add the `rust` tag to a custom egg whose startup wrapper hides the executable. The backend also requires `WORLD_SEED` and `WORLD_SIZE` variables.
5. Owners and administrators have access. Regrant subusers **ext.rust-server-wiper.manage**; the old `wipe.manage` permission is not reused. This permission authorizes deletion, server power actions, console commands, startup changes and map management.

## Existing schedules

Import is optional and explicit. Old tables remain intact. Back up the panel database before importing. Validate first:

```sh
php artisan rust-server-wiper:import-legacy --dry-run
php artisan rust-server-wiper:import-legacy
```

Import copies future one-time schedules, monthly repeat rules and map URLs. Imported schedules are **paused**. Review timezone, file patterns, identity directory, startup values and commands before enabling them. Imported identity defaults to `/server/rust`, as in the original addon. Completed one-time wipes, missing servers and invalid records are skipped and reported. Repeating the import does not duplicate records. Pre-5.1 recurrence encodings that are not JSON arrays need manual recreation. Invalid/past one-time dates need manual recreation. Legacy `force` is intentionally not used: this extension never forcibly kills an unresponsive server to proceed with deletion.

## Using the wiper

The interface separates **Schedules**, **Map library**, **Activity & backups**, and **Settings**. Creating or editing a wipe follows four steps:

1. **Wipe scope:** name the wipe, apply a preset, and review file patterns and the identity directory.
2. **Next map:** keep current settings or configure a procedural map, fixed custom URL, or saved map rotation.
3. **Timing & safeguards:** choose a one-time date or monthly repeat; review backups and optional player checks and announcements. Leave timing blank for an immediate wipe.
4. **Review:** check the summary and save the schedule, or preview matching files and acknowledge deletion before choosing **Wipe now**. Editing any option clears that acknowledgement.

Use Back or the step buttons to revisit a choice. Switching sections preserves the open draft; closing the editor discards it. Manage saved maps in **Map library**, reports and full-backup restores in **Activity & backups**, and Discord/WebRCON connections in **Settings**.


- Give each schedule a name and IANA timezone, such as `America/Phoenix`. Defaults to UTC. Dates are stored in UTC and displayed in the schedule's timezone.
- Select a one-time local date, or one or more monthly rules such as first Thursday at 14:00. A fifth weekday is skipped in months without that date. Nonexistent local times during a daylight-saving jump are skipped; ambiguous fall-back times use PHP's first chosen occurrence and run once.
- Select exact paths or filename patterns, one per line. Example for a map wipe:

  ```text
  /server/rust/*.map
  /server/rust/*.sav
  /server/rust/*.sav.*
  ```

  Paths are inside the server filesystem. No files are automatically selected for map wipes. **Wipe blueprints** adds blueprint `.db`, journal, WAL and SHM files under the identity directory. Change that directory to match your `server.identity`. Oxide/uMod and plugin data only change when explicitly selected.
- Use **Browse server files** to select exact files or directories. Wildcard patterns match files only. Exact directories are removed recursively by Wings. Root entries, bare `*`, traversal and symlinks are rejected; the selected paths must not cross symlink directories. Preview limits deletion to 5,000 matched entries (the contents of a selected directory are not counted separately).
- Blank optional startup fields retain existing values. Name/description support `%DAY%`, `%MONTH%`, `%YEAR%`. An explicit procedural seed or size clears a custom `MAP_URL` when the egg supports it. A new seed also updates exact `server.seed` directives in the identity's `cfg/server.cfg`, if present.
- A fixed custom map and map rotation are alternatives to procedural seed/size. Rotation avoids the current URL when alternatives exist; one saved map still works.
- Commands run 1–1,440 minutes before scheduled wipes. They are sent at most once per occurrence. Notifications missed during scheduler downtime are not replayed. A Wings command error is logged rather than silently swallowed.
- **Preview files** resolves matching entries without stopping or modifying the server. The preview must be reviewed and acknowledged before **Wipe now**. Editing settings clears the preview and acknowledgement. Immediate wipes skip scheduled announcements. File matches are resolved again after stopping, so a preview is not a frozen snapshot.
- **Pause** cancels queued runs. Editing/deleting an actively running schedule is blocked. Deleting a schedule keeps its run history.

## Execution and failures

The scheduler persists each occurrence, advances its next due time atomically, and submits its run to the standard queue. A per-server lock prevents concurrent wipes. Duplicate queue deliveries cannot claim the same run twice. Server installation, suspension, restore and transfer states are checked through the panel.

The worker validates paths and egg variables before stopping. It sends a graceful stop and checks Wings every two seconds for up to 180 seconds. It deletes nothing if the server does not stop. It checks the stopped state again before each deletion batch, writes startup values, syncs Wings, and sends start after successful deletion. Completion means the start signal was accepted, not that Rust finished booting.

Wipes more than 15 minutes late, including jobs delayed in the queue, are marked **missed**. After downtime, a recurring schedule advances to its next future occurrence instead of wiping unexpectedly. Failed or interrupted runs are **never automatically replayed**. History shows the latest 50 records; the database retains all records. Check the server and make a new reviewed immediate wipe if appropriate. A failed destructive operation may leave partial changes; there is no automatic rollback. Optional confirmed pre-wipe backups are described in FEATURES-2.1.md. Filesystem changes and panel startup/power operations use the panel's native actions and activity logging where provided.

Disabling the extension stops future scheduling and cancels jobs when they next check availability. If disabled after a server stop, the worker leaves the server stopped without deleting. A wipe already deleting files finishes its sequence to keep the server in a known state. A concurrent manual power action can still race between a stopped-state check and a Wings request; avoid manual starts during a wipe. Two different enabled schedules due together are serialized, so review overlapping schedules.

Check **Run history**, `storage/logs/laravel-*.log` (or your configured panel log), and **Admin → Extensions**. Check that `php artisan schedule:list` includes `rust-server-wiper:tick` and that workers consume `standard`. Use `php artisan rust-server-wiper:tick` to execute one scheduler pass manually; it may queue due destructive wipes.

## Development and verification

The source includes a vendored upstream SDK tarball because the official SDK package was unavailable from the public npm registry during conversion. It comes from the beta.1 panel source above, unchanged. It is only a build dependency; the panel supplies React and SDK runtime modules via its import map.

```sh
npm ci --legacy-peer-deps
npm run typecheck
npm test
npm run build
php tests/run.php /path/to/a/disposable/pterodactyl-checkout
```

PHP tests boot the panel's real Laravel framework with an in-memory SQLite database and a fake daemon. Use an uncached disposable checkout with its Composer dependencies installed, not your production panel. Tests cover monthly scheduling, DST, validation, path safety, stop failures, config handling, map rotation, duplicate prevention, permissions, server scoping and queue/run state. Frontend tests cover preview acknowledgement, preview invalidation, conflicting map settings, repeat controls and request errors.

Run package checks with the panel:

```sh
php artisan p:extension:doctor /path/to/rust-server-wiper
php artisan p:extension:pack /path/to/rust-server-wiper --output=/path/to/rust-server-wiper-2.1.0-beta.1.pteroext
```

The packaged extension includes runtime PHP, migrations, routes and built assets. You do not need Node or Composer installation to install the package. No live Rust server was used for local verification. Test the first wipe against a disposable Rust server and verify the identity path and egg before enabling production schedules.

## Provenance

Original supplied addon: UndercoverNL, Rust Server Wiper. This conversion is for the supplied user's panel; no new redistribution rights are granted for the original proprietary addon. The build SDK is from `pterodactyl/panel`, licensed MIT (see `SDK-LICENSE.txt`).

Documentation: https://docs.pterodactyl.io/v2/extensions
Upstream source: https://github.com/pterodactyl/panel/tree/65913c6aac5367a35f48b4448f027354b9b2a577
