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
