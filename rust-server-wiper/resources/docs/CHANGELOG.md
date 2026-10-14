# 2.1.0-beta.1

- Confirmed native pre-wipe backups, encrypted recovery values, full-server restore and restore history.
- Durable maintenance leases with 18 supported panel action guards and expired-operation recovery.
- Split view, schedule, execute, restore and configure permissions with bounded legacy compatibility.
- Reusable editable presets, built-in map/full presets and detected Rust identity suggestions.
- Ordered/weighted rotation, recent-map exclusion and public URL preflight with validated redirects.
- Upcoming schedule agenda, skipped dates/months and overlapping-wipe warnings.
- Administrator multi-server preview and staggered one-time batch scheduling with independent results.
- Player-aware deferral, maximum deadline and explicit unknown-count/deadline policies.
- Encrypted Discord and WebRCON settings, at-most-once notifications and a saved-config probe.
- Wings/Rust readiness checks that distinguish file wipe completion from startup readiness.
- Reports with actor metadata, timeline, duration, backup/seed/map/files and redacted JSON export.
- UUID request IDs protect completed immediate wipes, restores and batches against duplicate retries.
- Queue timeout increased to 1,200 seconds; reservation must be longer (recommended 1,260 seconds).
- Existing baseline schedules, maps and history survive migration. Live integration checks remain pending.

# 2.0.0

- Native Pterodactyl 2.0 provider, server screen, registered permissions, routes, migrations, commands and scheduler; no core patches.
- UTC storage with named schedule timezones, strict date validation and corrected monthly ordinal weekday calculation across missing fifth weekdays and DST.
- Durable occurrence records, atomic claims, server locks, queued-run recovery and explicit missed/failed/cancelled states. No automatic replay of destructive failures.
- Graceful-stop verification before deleting; no fixed one-minute delay or forced kill.
- Validated startup values, optional fields preserved, missing variables reported, map rotation with one map supported, current map avoided when alternatives exist.
- Anchored server.seed updates, seed zero retained, missing config handled distinctly from daemon failures.
- CRLF support, exact-path selection, filename-only wildcards, batch deletion, path and symlink checks, blueprint WAL/SHM cleanup.
- Scoped schedule/map ownership checks, validated nested command rules and explicit extension authorization on every endpoint.
- File browser, deletion previews, immediate-wipe acknowledgement, schedule pause, responsive controls and durable run history.
- Optional legacy import with dry-run, idempotency, validation and paused imported schedules.
