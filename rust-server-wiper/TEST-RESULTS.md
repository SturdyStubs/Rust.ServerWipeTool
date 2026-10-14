# Verification results

Verified on October 13, 2026, against Pterodactyl **2.0.0-beta.1**, upstream commit `65913c6aac5367a35f48b4448f027354b9b2a577`, SDK **2.0.0-beta.6**.

| Check | Result |
| --- | --- |
| PHP regression and integration suite | 63 passed |
| React interface regression suite | 7 passed |
| PHP syntax checks | All PHP files pass |
| TypeScript strict type check | Pass |
| Production frontend build | Pass |
| npm dependency audit | 0 known vulnerabilities |
| Pterodactyl extension doctor | Pass |
| Packaged extension install and enable | Pass |
| Extension migration, provider, routes, commands, schedule registration | Pass |
| Desktop 1440 px and mobile 390 px browser fixture | No page errors or page-level horizontal overflow |

The PHP suite boots the real beta panel framework with isolated SQLite and a simulated daemon. It checks destructive-operation ordering and failure handling without touching live server files. The install test uses the real panel extension installer and a minimal isolated panel schema. This is not a full MySQL/MariaDB deployment test.

Browser checks use an explicit simulated server fixture with approximations of the native shell and buttons. Production uses the panel's actual SDK components and active theme. Screenshots are in `tests/screenshots` after running `tests/preview/capture.mjs` against the preview server.

No live Wings host or Rust server was connected. Stop latency, actual map download, container startup and interactions with third-party extensions still need a disposable-server check on the user's panel.
