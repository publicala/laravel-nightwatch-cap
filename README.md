# Laravel Nightwatch Cap

[![Latest Version on Packagist](https://img.shields.io/packagist/v/publicala/laravel-nightwatch-cap.svg)](https://packagist.org/packages/publicala/laravel-nightwatch-cap)
[![CI](https://github.com/publicala/laravel-nightwatch-cap/actions/workflows/ci.yml/badge.svg)](https://github.com/publicala/laravel-nightwatch-cap/actions/workflows/ci.yml)
[![License](https://img.shields.io/packagist/l/publicala/laravel-nightwatch-cap.svg)](LICENSE)

Laravel Nightwatch bills events per organization and pauses ingestion for every application when the organization reaches its limit. This package gives each application its own monthly budget: once an application's batches add up to its limit, it stops sending events until the calendar month rolls over, so one application cannot spend the organization's allowance on its own.

```bash
composer require publicala/laravel-nightwatch-cap
```

It needs `laravel/nightwatch` 1.30 or later, Laravel 12.61 or later, and PHP 8.4 or later.

## What it does

Nightwatch dispatches `Laravel\Nightwatch\Events\IngestingEvents` before it sends each batch of records. The package listens to it and:

- **Counts the batch** in the cache, whether or not it goes on to send it, under `nightwatch-events:2026-11` for November 2026. User records are not billed by Nightwatch and are not counted. The count expires after 32 days, so it outlasts a month that holds a clock change.
- **Lets the batch through** while the month's count, this batch included, stays at or under the limit.
- **Drops the batch** that carries the count past the limit, and every batch after it until the month rolls over. The batch that crosses the limit logs one warning:

  ```text
  nightwatch.ingest_capped {"reason":"monthly_event_limit_reached","limit":1000000}
  ```

- **Lets the batch through uncounted** when a cache call throws or the limit is not a whole number, because Nightwatch drops a batch whose listener throws and reports nothing. Each failure logs a warning, with the reason `counter_unavailable` or `invalid_limit`:

  ```text
  nightwatch.ingest_uncounted {"reason":"counter_unavailable","exception":"Connection refused"}
  ```

  A logger that throws does not stop the batch either. The warnings are best effort.

Nightwatch ignores everything an application records while an `IngestingEvents` listener runs, so neither warning reaches Nightwatch. Read them in your application logs.

The month is the calendar month in `app.timezone`, whatever timezone the process runs in at the time. A Nightwatch billing cycle starts on the plan's start date, so the cap bounds a different window than the invoice.

Other `IngestingEvents` listeners still run for a batch under the limit. The package registers its listener before the application's own providers boot, so a listener the application registers runs after it.

## Alerts

Every five minutes the scheduler runs `nightwatch-cap:report-alerts`, which reports the month's count to Nightwatch as an unhandled exception once it reaches a threshold:

| Count | Exception |
| --- | --- |
| 80% of the limit | `Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching` |
| The limit | `Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached` |

```text
acme-app has counted 8,000,000 Nightwatch events for 2026-11, reaching its limit of 8,000,000, and sends nothing more until the month rolls over. To resume before then, raise nightwatch.monthly_event_limit above the count and restart the application's workers.
```

The count is what the application tried to send, the batches the cap dropped included.

- **Each month opens its own issue.** The exception code is the month (`202611`), and Nightwatch groups exceptions by class, code, file and line. A new issue reaches the channels the application's Nightwatch notifications go to.
- **An alert goes out past the limit.** Nightwatch sends an unhandled exception at once, in a batch of its own. The cap lets a batch that holds only one alert through, uncounted, and drops a batch that carries anything else.
- **An alert gets up to three reports, five minutes apart.** Nightwatch confirms nothing about a report, so the first three runs past a threshold each report it, and the repeats land in the same issue. Every scheduler that shares the counter's store shares the five minutes, so several servers running the schedule still report once each five minutes. The limit reached is reported even when the 80% alert was not.
- **Raising the limit re-arms both alerts.** Reports are counted per month, alert and limit, so the count reaching a raised limit reports again.
- **Each report logs a warning** in the application logs, a trace that does not depend on Nightwatch:

  ```text
  nightwatch.cap_alert_reported {"alert":"Publicala\\NightwatchCap\\Exceptions\\MonthlyEventLimitReached","report":1,"message":"..."}
  ```

- **Delivery and notification depend on Nightwatch and on the application's exception handling.** The report goes through Nightwatch's agent, so an agent that is down loses it. Nightwatch asks the application's exception handler whether to report it (`shouldReport()`: `dontReport`, `throttle`), so a handler that declines it sends no alert. `reportable()` callbacks do not run. A redaction callback can change what arrives.
- **A run sends only its alert.** The schedule entry samples the run at zero. A run that reports samples the report alone: what the application records while deciding whether to report it is ignored, and a job it dispatches is not sampled.
- **A run with Nightwatch disabled reports nothing and uses none of the attempts.**
- **A run whose counter fails, or whose limit is not a whole number, reports nothing.** It logs `nightwatch.cap_alert_skipped` with the reason `counter_unavailable` or `invalid_limit` and uses none of the attempts.
- **No schedule entry without Nightwatch.** When Nightwatch's provider fails to register, the package schedules nothing and the command does nothing, so the application's other scheduled tasks still run.
- **Called from a request or job, the command hands back the sampling it found.** The scheduler runs it in a process of its own.

The package never lowers the count. To resume sending before the month rolls over, raise `nightwatch.monthly_event_limit` above the count and restart every process that booted with the old limit (`queue:restart`, `octane:reload`). A lower sample rate only slows the count toward the next limit.

An application that runs no scheduler gets no alerts. A threshold crossed in the last five minutes of a month is not reported, since the next run reads the new month's count.

## Configuration

Set the limit in `config/nightwatch.php`, next to Nightwatch's own keys:

```php
'monthly_event_limit' => (int) env('NIGHTWATCH_MONTHLY_EVENT_LIMIT', 1_000_000),
```

Add `event_limit_cache_store` only to keep the count in a store of its own, such as `'event_limit_cache_store' => 'nightwatch'`. The key moves the count: a store other than the one that held it starts the month again from zero.

| Key | Default | Effect |
| --- | --- | --- |
| `monthly_event_limit` | `1000000` | Events a calendar month before the package drops batches |
| `event_limit_cache_store` | not set | The cache store that holds the count. Without the key, the count lives in the store Laravel's rate limiter uses (`cache.limiter`, else the default store). With the key set to `null`, it lives in the default store. |

Web servers, queue workers and scheduled commands must all increment the same count. Use a store they share, such as `database` or Redis. An `array` store keeps a count per process, and a `file` store a count per server, so neither enforces one budget for the application.

The count lives as long as its store keeps it:

- `cache:clear` and `optimize:clear` flush the default store, and a Redis flush clears the whole Redis database whatever the prefix. A count in a flushed store starts from zero, and so do its alert reports. Keep the count in a store, or Redis database, that deploys do not clear.
- Under memory pressure, Redis evicts keys, and a `volatile-*` policy picks keys with a TTL such as the count. Give the count's Redis room, or a `noeviction` policy.
- A `database` store on the application's connection writes inside the application's open transaction. A rolled-back transaction rolls back the batches it counted, and the count's row lock waits for the transaction to end. Point the store at a connection of its own.

## License

MIT. See [LICENSE](LICENSE).
