# Version 2.3.1-beta.1

Fixes saved schedule times shifting when the panel's APP_TIMEZONE is not UTC. The extension now reads and writes its own model timestamps explicitly as UTC, including run creation timestamps used by queue-age checks. Selected IANA timezones remain wall-clock display and recurrence settings.

Reproduced case: October 14, 2026 at 11:56 PM America/Phoenix must save as October 15 at 06:56 UTC, epoch 1792047360. The previous default cast could reread it as Phoenix 06:56, producing the screenshot's incorrect epoch 1792072560 and 06:56 AM display. Regression checks cover Phoenix and Paris panel defaults, create/reload/calendar output, offset writes, nullable dates and scheduler execution at the exact instant.

Pause affected test schedules before installing. After updating and restarting workers, reload the panel, inspect saved times and choose a new future time where the original time has passed. This release does not rewrite database dates or replay runs. UTC schedule values already persisted by the input parser are preserved and read correctly. Historical audit timestamps created automatically under a non-UTC panel default can be ambiguous; they are not bulk-shifted.

Install rust-server-wiper-2.3.1-beta.1.pteroext with --replace --enable and restart queue workers as described in the operator guide. No schema migration or panel timezone change is required. Existing server data is untouched by the update; normal enabled schedules continue operating after reload/worker restart.
