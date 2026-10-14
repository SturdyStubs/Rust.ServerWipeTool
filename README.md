# Rust Server Wipe Tool

The converted Pterodactyl 2.0 extension is in [rust-server-wiper](rust-server-wiper/README.md).

Baseline package: **rust-server-wiper-2.0.0.pteroext**.

Development package: **rust-server-wiper-2.1.0-beta.1.pteroext**. All twelve planned features are implemented; read [the beta feature and upgrade guide](rust-server-wiper/FEATURES-2.1.md) before installation. Live Rust/Wings staging checks remain open.

Read the extension README before installation: it covers queue configuration, disabling the old runner, importing legacy data paused, permissions, and first-wipe checks.

This repository tracks the native Pterodactyl 2.0 extension. The original purchased addon and local test tools are excluded from version control.

## Versions

- `main`: tested baseline versions.
- `v2.0.0`: initial native extension conversion.
- `feature/v2.1.0`: development of the next feature release.

The extension version and `package.json` version are kept together. Development versions use a prerelease suffix until their checks pass. The next feature branch contains its implementation checklist in `ROADMAP.md`.
