# v2.3.0 implementation checklist

- [x] Modular encrypted webhook destinations, message/embed presets and many-to-many event rules.
- [x] Separate reminders, pre-stop, observed-running and failure events, scoped to all wipes or one schedule.
- [x] Dedicated delivery queue, worker probe, independent receipts, rate-limit handling and stale-message cancellation.
- [x] Optional text and rich embeds, safe previews, explicit mention controls and Discord payload validation.
- [x] Epoch values and instant-copy menus for raw seconds, default and all nine documented Discord timestamp styles.
- [x] Preserve legacy Discord settings until modular configuration is saved.
- [x] Backend and UI regression tests and desktop/mobile browser fixtures.
- [ ] Verify actual asynchronous delivery, Discord rendering and live Rust/Wings event timing before stable release.

# v2.2.0 implementation checklist

- [x] Read-only per-plan health checks with shared execution preflight, backup quota, Wings/RCON checks, scheduler heartbeat and a harmless standard-queue probe.
- [x] Versioned configuration JSON export/import with review, identity-relative paths, paused schedules, limits, atomic writes and durable retry receipts.
- [x] Browser-local default timezone, native timezone dropdown, server clock, clock-skew warning and contextual future-date validation.
- [x] Weekly and anchored 14-day repeats shared by execution, calendar and import.
- [x] Skip exactly one future occurrence with an expected-date check, audit entry and active-operation guards.
- [x] Backend and UI regression coverage, including one-minute-ahead Phoenix scheduling under a different PHP default timezone.
- [ ] Verify actual backup/restore, map downloads, Rust readiness, queue probes and RCON on a disposable live server before a stable release.

# v2.1.0 implementation checklist

All twelve features below were approved for implementation. This branch starts from the tested `v2.0.0` baseline. All twelve features are implemented in `2.1.0-beta.1` and covered by local regression checks. Unchecked release items still require a real staging environment; this is not a stable release.

## Backups, restore and maintenance

- [x] **Automatic pre-wipe backups and restore.** Persist backup identity, selected files and the previous startup values alongside each run. Require confirmed backup completion before deletion. Provide an explicit restore action, with stopped-server checks, permissions and durable restore history. A failed backup must leave the wipe unexecuted.
- [x] **Maintenance mode during wipes.** Block ordinary panel power actions and filesystem mutations during a wipe using supported extension action wrappers. Expose maintenance status and safe recovery for interrupted workers. Keep extension-owned stop, backup, delete, restore and start operations functional.
- [x] **Separate permissions.** Add view, schedule management, immediate execution, backup/restore and configuration permissions with matching API and screen enforcement. Define compatibility with the existing `manage` grant so upgrades do not unexpectedly broaden access. Server owners and administrators retain their normal access.

## Presets, files and maps

- [x] **Reusable wipe presets.** Save, edit and apply map-only, full/blueprint and explicitly selected plugin-data presets. Applying a preset must expose its exact file patterns for review rather than quietly scheduling or executing a wipe.
- [x] **Rust identity detection.** Inspect startup and environment values to suggest the identity directory and map/save/blueprint patterns. Show the detected source, preserve manual overrides and report when detection is uncertain. Support custom eggs and startup wrappers.
- [x] **Map rotation rules and URL checks.** Support ordered and weighted rotation, configurable recent-map exclusion and a documented fallback for small map pools. Record the chosen map. Validate the selected URL before stopping, bound requests and reject internal-address redirects. Test sequence, weights, failed URLs and single-map pools.

## Scheduling and orchestration

- [x] **Upcoming-wipe calendar.** Preview multiple future occurrences in each schedule's timezone, show fifth-weekday and daylight-saving skips, and flag overlapping server schedules. Share the same recurrence implementation used by execution.
- [x] **Multi-server administration.** Add an administrator screen for applying presets across explicitly selected servers, staggering wipe times and monitoring per-server results. Validate each server's egg, paths and permissions independently. A failure on one server must not erase the other results.
- [x] **Player-aware scheduling.** Add an opt-in player-count check and deferral policy with a maximum deadline. Detect unavailable player information and follow an explicit configured fallback. Use bounded retries and show why a wipe was deferred or cancelled.

## Notifications, readiness and reports

- [x] **Discord notifications.** Configure encrypted webhook secrets, event selection, templates and optional mentions. Send upcoming-wipe, completion/readiness and failure notifications without duplicate deliveries. Keep webhook errors separate from wipe execution and never expose secret URLs in frontend settings or reports.
- [x] **Rust startup verification.** Distinguish files wiped, start signal accepted and server ready. Use bounded checks of Wings and a configured Rust readiness signal, with a clear timeout result. An uncertain start response must not trigger another destructive wipe.
- [x] **Wipe reports.** Persist initiator and schedule-change attribution, chosen seed/map, removed files, backup reference, phase timestamps and duration. Provide a readable report and an export that omits credentials. Include partial changes and failure stage.

## Release checks

- [x] Add migrations that preserve existing schedules, maps and run history.
- [x] Expand backend and interface tests for every feature, including failures and authorization boundaries.
- [x] Check native extension installation, enabling, updating and disabling against the tested Panel 2.0.0-beta.1; other panel versions are not claimed as verified.
- [x] Inspect desktop and mobile server/editor/admin fixtures; live-theme long-path and partial-failure checks remain part of staging verification.
- [x] Update operator documentation and packaged runtime documentation.
- [x] Keep development packages visibly marked as prereleases until release validation is complete.
- [ ] Validate actual backup/restore, Rust readiness and player-count behaviour against a disposable Rust/Wings server before declaring live integration verified.
- [x] Produce versioned `.pteroext` artifacts and checksums after checks pass; retain `v2.0.0` as the baseline.
