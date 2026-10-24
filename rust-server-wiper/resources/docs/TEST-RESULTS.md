# Verification results - 2.3.1-beta.1

| Check | Result |
| --- | --- |
| PHP regression/integration suite | 156 passed |
| React interface regression suite | 27 passed |
| TypeScript and production build | Pass |
| Native doctor, fresh install and provider boot | Pass |
| Data-preserving upgrades from 2.0.0, 2.2.0-beta.1 and 2.3.0-beta.1 | Pass |

Reproduced the reported seven-hour shift with a Phoenix panel default: entered 2026-10-14 23:56, expected epoch 1792047360, previously returned 1792072560. Added create/JSON/reload/calendar regressions under Phoenix and Paris defaults, timestamp/offset/null conversion checks for operational models, exact-instant scheduler execution and UTC run creation timestamps, plus UI checks for local date, epoch and edit-field round-trip.

All daemon/server operations in these tests are simulated in a disposable reference panel. An existing local WebRCON socket test timed out once during development; the subsequent full suite passed. No live schedules were changed, no Discord messages were sent, and live deployment remains unverified.

---

# Verification results - 2.3.0-beta.1

Verified locally on October 14, 2026 against the same disposable panel checkout described below.

| Check | Result |
| --- | --- |
| PHP regression and integration suite | 152 passed |
| React interface regression suite | 26 passed |
| TypeScript strict type check and production build | Pass |
| Desktop/mobile announcement editor, rich message preview and timestamp menu | No page errors or page-level overflow; clipboard copying checked |
| Native extension doctor and packaging | Pass |
| Fresh package installation, migrations, routes, provider and scheduler registration | Pass |
| Data-preserving upgrades from 2.0.0 and 2.2.0-beta.1 | Pass |
| Fresh provider boot and disable/re-enable after upgrade | Pass |

New backend coverage includes encrypted configuration/payloads, masked webhook reads, preserved blank secrets, stale-save rejection, optional text/embeds and Discord limits, multiple rules/destinations, separate reminder offsets, scoped schedules, mentions/tokens, safe previews, cancelled/expired messages, explicit rate-limit retries, ambiguous response isolation, pre-stop/observed-running event boundaries, failed-start handling, real wipe-job notification dispatch, failure deduplication and legacy compatibility.

UI coverage checks composing multiple destinations/rules, embed-only messages, role-ID entry, safe previews, every documented timestamp format, keyboard navigation and clipboard failures. Browser fixtures approximate the panel theme and do not connect to a live server. No Discord messages were sent. Actual asynchronous worker delivery and live Discord/Rust/Wings behavior remain unverified.

---

# Verification results - 2.2.0-beta.1

Verified locally on October 14, 2026 against the same disposable Pterodactyl 2.0.0-beta.1 checkout and SDK 2.0.0-beta.6 described below.

| Check | Result |
| --- | --- |
| PHP regression and integration suite | 132 passed |
| React interface regression suite | 22 passed |
| TypeScript strict type check and production build | Pass |
| Native extension doctor and packaging | Pass |
| Fresh package installation, migrations, routes, provider and scheduler registration | Pass |
| Data-preserving upgrade from v2.0.0; fresh provider boot and disable/re-enable | Pass |
| Desktop/mobile workflow, timezone default, health results, import review, settings and batch admin | No page errors or page-level horizontal overflow |

New coverage includes a Phoenix time one minute ahead with a different PHP default timezone; contextual past-time errors; weekly/14-day month-boundary and DST behavior; stale/repeated skip requests; non-mutating health checks; worker acknowledgement polling; quota/RCON/map failures; server-scoped probes and exports; import validation, identity adaptation, paused schedules, transactional rollback, record limits and durable retry protection.

Health tests use a simulated gateway and synchronous test queue; the UI acknowledges that this cannot demonstrate a separate worker. Browser screenshots use a labelled fixture, not a live server. Live Wings/Rust backup, restore, custom map loading, asynchronous queue-probe delivery, RCON and third-party extension interaction remain unverified. Do not interpret local results as live staging approval.

---

# Verification results — 2.1.0-beta.1

Verified on October 13, 2026, against Pterodactyl **2.0.0-beta.1**, upstream commit `65913c6aac5367a35f48b4448f027354b9b2a577`, SDK **2.0.0-beta.6**.

| Check | Result |
| --- | --- |
| PHP regression and integration suite | 110 passed |
| React interface regression suite | 11 passed |
| PHP syntax checks | All PHP files pass |
| TypeScript strict type check | Pass |
| Production frontend build | Pass |
| npm dependency audit | 0 known vulnerabilities |
| Pterodactyl extension doctor | Pass |
| Packaged extension install and enable | Pass |
| Extension migration, provider, routes, commands, schedule registration | Pass |
| v2.0.0 → beta upgrade and baseline data preservation | Pass |
| Disable/re-enable after upgrade | Pass |
| WebRCON handshake, masked request, ping and fragmented reply against local mock | Pass |
| Desktop 1440 px and mobile 390 px browser fixture | No page errors or page-level horizontal overflow |

The PHP suite boots the real beta panel framework with isolated SQLite and a simulated daemon. It checks destructive-operation ordering, confirmed backups, restores, maintenance, permissions, secret storage, public URL checks, notification isolation, calendar skips, players, reports, batch scheduling and UUID idempotency. It never touches live server files. Install and upgrade tests use the real panel extension installer and minimal isolated schemas, with fresh PHP processes across upgrade phases. This is not a full MySQL/MariaDB deployment test.

Browser checks cover server overview/editor and administrator pages at 1440 and 390 pixels. They use an explicit simulated server fixture with approximations of the native shell and buttons. Production uses the actual SDK components and active theme. Screenshots are in `tests/screenshots` after running `tests/preview/capture.mjs` against the preview server.

No live Wings host, Rust server or Discord webhook was connected. Actual backup/restore, stop latency, map download, container startup, player counts, notification delivery and interactions with third-party extensions need a disposable-server check before a stable release.
