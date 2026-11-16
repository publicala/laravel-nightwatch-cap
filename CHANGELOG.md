# Changelog

All notable changes to `publicala/laravel-nightwatch-cap`.

The top `## ` header is always the most recent version (`## vX.Y.Z`, exact), and that header is the source of truth the release workflow reads. Adding a new top block to this file is what cuts a release.

## v0.4.0

### Changed

- **`nightwatch-cap:report-alerts` runs hourly, at a minute past the hour picked from `app.name`.** The minute is a prime from 7 to 59, clear of `:00` and the five-minute marks, so applications that share a host or a Nightwatch organization spread across the hour. Before, every application ran it every five minutes.

### Upgrade

An alert reaches Nightwatch up to an hour after the count crosses its threshold, and its three reports go out an hour apart. A threshold crossed after a month's last run is not reported.

## v0.3.0

### Fixed

- **An application whose Nightwatch provider fails to register keeps its scheduler.** The alert entry is scheduled, and the command runs, only when Nightwatch bound its `Core`. Before, `Sample::never()` threw while the schedule was built, and no scheduled task ran.
- **Raising the limit re-arms the alerts.** Reports are counted per month, alert and limit.
- **An alert gets at most one report each four minutes across every scheduler that shares the counter's store.** Before, several servers could spend all three reports in the same minute. Each report handed to Nightwatch logs `nightwatch.cap_alert_reported`.
- **A scheduler that cannot reach Nightwatch's agent leaves the report to the others.** The run pings the agent before it takes the lock, and on failure logs `nightwatch.cap_alert_skipped` with the reason `agent_unreachable`.
- **A limit that is not a whole number counts the batch and lets it through**, logging `nightwatch.ingest_uncapped` with the reason `invalid_limit`, and the alert command logs `nightwatch.cap_alert_skipped` with the same reason. A string of digits is read as its number, and a negative limit is invalid. Before, every batch was dropped. A logger that throws no longer stops a batch or fails the alert command.
- **The month follows `app.timezone`**, whatever timezone the process runs in, **and the count lasts 32 days**, past a month that holds a clock change. A count already in the store keeps the TTL it was created with.
- **Called from a request or job, the alert command records nothing of its own and hands back the sampling it found**, including the sampling an enclosing `Nightwatch::ignore()` passes to queued jobs.

### Changed

- The alert messages say "counted" instead of "sent", since the count includes the batches the cap dropped. The limit reached message also asks to restart the application's workers after raising the limit.
- The README's configuration example leaves `NIGHTWATCH_MONTHLY_EVENT_LIMIT` uncast, so a mistyped value reaches the invalid limit handling instead of a limit of `0`.

### Upgrade

Every application that runs the scheduler gets the `nightwatch-cap:report-alerts` entry, which needs Nightwatch enabled and its agent reachable from the scheduler's server, and the same counter store as the web and queue processes. Alert reports already recorded under v0.2.0 do not carry over, so an alert reported this month under v0.2.0 can be reported again. A limit config that casts with `(int)` keeps working, and dropping the cast lets a mistyped value through instead of dropping every batch.

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
