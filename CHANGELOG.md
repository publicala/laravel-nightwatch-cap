# Changelog

All notable changes to `publicala/laravel-nightwatch-cap`.

The top `## ` header is always the most recent version (`## vX.Y.Z`, exact), and that header is the source of truth the release workflow reads. Adding a new top block to this file is what cuts a release.

## v0.2.0

### Added

- **Alerts in Nightwatch at 80% and 100% of the monthly limit.** A scheduled command, `nightwatch-cap:report-alerts`, runs every five minutes without sampling and reports `MonthlyEventLimitApproaching` or `MonthlyEventLimitReached` as an unhandled exception coded with the month, so each month opens its own issue. An alert passes the cap uncounted, and each one is reported on three runs.

## v0.1.1

### Changed

- **The monthly count lives in `MonthlyEventCounter`.** The cap reads its limit and adds each batch through it. Behavior is unchanged.

## v0.1.0

### Added

- **A monthly cap on the events an application sends to Nightwatch.** A listener on `IngestingEvents` counts each batch in the cache under `nightwatch-events:Y-m`, sent or not, and drops every batch past `nightwatch.monthly_event_limit` (1,000,000 when unset) until the calendar month rolls over. It logs `nightwatch.ingest_capped` once, on the batch that crosses the limit, and lets a batch through uncounted, logging `nightwatch.ingest_uncounted`, when a cache call throws.
