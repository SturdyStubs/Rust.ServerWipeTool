# Version 2.2.0-beta.1

This prerelease adds pre-wipe health checks, portable configuration files, timezone improvements, weekly/14-day schedules, and skip-next controls. It retains the v2.1 backup, maintenance, permissions, and worker configuration requirements in [FEATURES-2.1.md](FEATURES-2.1.md). Live Rust/Wings verification remains pending.

## Upgrade

Back up the panel database and install `rust-server-wiper-2.2.0-beta.1.pteroext` through the native extension installer. Existing schedules and history are retained. The new migration creates an import receipt table used to prevent duplicate configuration imports. Restart queue workers after upgrading, then reload the panel. The `standard` queue, minute scheduler, shared persistent cache, and reservation longer than the 1,200-second worker timeout remain required.

## Timezones and near-future wipes

New server and administrator forms default to the browser's IANA timezone. Existing schedules retain their saved timezone. Timezones are selected from a dropdown; the server supplies its supported identifiers. Dates entered in the date/time control are interpreted in the selected timezone, regardless of the browser's timezone.

The form displays a live estimate of the server clock in the selected timezone and warns when device/server clocks differ by more than two minutes. **Set to five minutes from now** uses the panel's clock. The backend remains the authority: genuinely expired dates are rejected, and the error includes the selected timezone and current server time. Minute-only date inputs resolve to second zero; a time can expire while a user reviews the form. Tests cover a Phoenix date one minute ahead while PHP uses a different default timezone. No offset arithmetic defect was reproduced; the old UTC default was a source of mismatched expectations.

## Scheduling

- **Monthly weekday** keeps the original ordinal rules such as first Thursday at 14:00.
- **Every week** repeats on a selected weekday at the same local wall-clock time, across month boundaries.
- **Every 14 days** repeats from an explicit anchor date at the same local wall-clock time. It is not an approximation using first/third weekdays. Daylight saving can change the elapsed hours between dates; nonexistent local times are skipped without moving the anchor. Ambiguous fall-back times follow PHP's existing interpretation and run once.
- **Skip next** records a skipped occurrence in Activity & backups and advances a recurring schedule exactly once. A one-time schedule has no next run afterward. Active/queued/deferred operations cannot be skipped. The request includes the expected occurrence, so a stale or repeated click cannot skip an additional date.
- Edit a schedule to move a one-time date or change its repeat rules.

The calendar, scheduling worker, import preview, and editor share the same recurrence interpretation. No database conversion is needed for existing monthly rules.

## Pre-wipe health check

In **Create/Edit wipe -> Review**, choose **Run health check**. This reads server state and files; it does not stop/start the server, create a backup, delete files, change startup values, or execute console commands. It sends one harmless acknowledgement job to the `standard` queue.

Checks cover extension availability, other wipe/restore/maintenance operations, Wings connectivity and server state, Rust egg variables, file patterns, map URL and startup validation, backup slot quota, optional WebRCON, scheduler heartbeat, shared-cache configuration, and queue acknowledgement. Configuration errors include a recovery instruction. Raw connection exception text is kept in panel logs so endpoint responses cannot leak credentials.

A worker acknowledgement is polled for up to 30 seconds. A timeout is a warning because the worker may be busy. Synchronous queues or array/null cache stores do not demonstrate a separate worker; the UI reports that limitation. Scheduler status is based on a completed tick within the last three minutes, not a guess from process names. A newly upgraded installation may not have a heartbeat until its next tick.

Health results are cleared after any edit. Results describe the current moment; all execution checks still run again. Backup quota does not prove available disk space or storage-provider health. A stopped Rust server cannot answer RCON. Rotation checks one possible map; the actual selection is repeated at execution.

## Configuration export/import

Users with the schedule permission can open **Import / export**.

**Export** downloads a versioned JSON document containing this server's schedules, presets, and map library. It omits identifiers, users, run/restore history, Discord and RCON connection settings, console commands, and URL credentials/query strings/fragments. Names, descriptions, paths, and URL paths remain content supplied by the operator; review the file before sharing. Signed map links may need to be entered again.

**Import** accepts JSON up to 2 MB. Select the destination Rust identity directory and preview the result. Paths equal to or beneath each source identity directory are adapted to that destination. Other paths remain unchanged and are explicitly listed for review. Expand entries to inspect file patterns and all payload options, including any commands present in a manually edited configuration.

After acknowledgement, the import adds records atomically and preserves all existing data and connection settings. Every imported schedule starts paused. Past/completed one-time schedules have no next run and require a new future date. Recurring schedules compute their next future occurrence in their own timezone. Existing limits of 100 schedules, presets, and maps per server also apply to imports. Invalid schemas, unsafe paths, incompatible map options, or exhausted record limits reject the whole import. Durable request receipts prevent retries of the same import request from creating duplicate records.

Before enabling imported schedules, review the paths, restore any required signed URLs/announcements, and run a health check. Importing configuration never runs a wipe.
