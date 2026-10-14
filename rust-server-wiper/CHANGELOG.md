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
