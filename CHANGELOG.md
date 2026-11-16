# Changelog

All notable changes to `publicala/laravel-nightwatch-cap`.

The top `## ` header is always the most recent version (`## vX.Y.Z`, exact), and that header is the source of truth the release workflow reads. Adding a new top block to this file is what cuts a release.

## v0.2.2

### Fixed

- **An application whose Nightwatch provider fails to register keeps its scheduler.** The alert entry is scheduled, and the command runs, only when Nightwatch bound its `Core`. Before, `Sample::never()` threw while the schedule was built, and no scheduled task ran.
- **Raising the limit re-arms the alerts.** Reports are counted per month, alert and limit.
- **An alert gets one report each five minutes across every scheduler that shares the counter's store.** Before, several servers could spend all three reports in the same minute. Each report logs `nightwatch.cap_alert_reported`.
- **A limit that is not a whole number lets batches through uncounted**, logging `nightwatch.ingest_uncounted` with the reason `invalid_limit`, and the alert command logs `nightwatch.cap_alert_skipped` with the same reason. A numeric string is read as its number. A logger that throws no longer stops a batch.
- **The month follows `app.timezone`**, whatever timezone the process runs in, **and the count lasts 32 days**, past a month that holds a clock change. A count already in the store keeps the TTL it was created with.
- **Called from a request or job, the alert command hands back the sampling it found.**

### Changed

- The alert messages say "counted" instead of "sent", since the count includes the batches the cap dropped. The limit reached message also asks to restart the application's workers after raising the limit.

### Upgrade

Every application that runs the scheduler gets the `nightwatch-cap:report-alerts` entry, which needs Nightwatch enabled and its agent reachable from the scheduler's server, and the same counter store as the web and queue processes. Alert reports already recorded under v0.2.0 do not carry over, so an alert reported this month under v0.2.0 can be reported again.

## v0.2.1

### Changed

- **`MonthlyEventCounter::month()` names the current month for both the cap and the alert command, and the command resolves Nightwatch's `Core` when it runs.** Behavior is unchanged.

## v0.2.0

### Added

- **Alerts in Nightwatch at 80% and 100% of the monthly limit.** A scheduled command, `nightwatch-cap:report-alerts`, runs every five minutes without sampling and reports `MonthlyEventLimitApproaching` or `MonthlyEventLimitReached` as an unhandled exception coded with the month, so each month opens its own issue. An alert passes the cap uncounted and gets up to three reporting attempts. A run with Nightwatch disabled, or whose counter fails, reports nothing and uses none of them. A failed counter logs `nightwatch.cap_alert_skipped`.

## v0.1.1

### Changed

- **The monthly count lives in `MonthlyEventCounter`.** The cap reads its limit and adds each batch through it. Behavior is unchanged.

## v0.1.0

### Added

- **A monthly cap on the events an application sends to Nightwatch.** A listener on `IngestingEvents` counts each batch in the cache under `nightwatch-events:Y-m`, sent or not, and drops every batch past `nightwatch.monthly_event_limit` (1,000,000 when unset) until the calendar month rolls over. It logs `nightwatch.ingest_capped` once, on the batch that crosses the limit, and lets a batch through uncounted, logging `nightwatch.ingest_uncounted`, when a cache call throws.
