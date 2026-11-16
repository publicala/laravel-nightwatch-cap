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

- **Counts the batch** in the cache, whether or not it goes on to send it, under `nightwatch-events:2026-11` for November 2026. User records are not billed by Nightwatch and are not counted. The count expires after 31 days.
- **Lets the batch through** while the month's count, this batch included, stays at or under the limit.
- **Drops the batch** that carries the count past the limit, and every batch after it until the month rolls over. The batch that crosses the limit logs one warning:

  ```text
  nightwatch.ingest_capped {"reason":"monthly_event_limit_reached","limit":1000000}
  ```

- **Lets the batch through uncounted** when a cache call throws, because Nightwatch drops a batch whose listener throws and reports nothing. Each failure logs a warning:

  ```text
  nightwatch.ingest_uncounted {"reason":"counter_unavailable","exception":"Connection refused"}
  ```

Nightwatch ignores everything an application records while an `IngestingEvents` listener runs, so neither warning reaches Nightwatch. Read them in your application logs.

The month is the calendar month in the application's timezone. A Nightwatch billing cycle starts on the plan's start date, so the cap bounds a different window than the invoice.

Other `IngestingEvents` listeners still run for a batch under the limit. The package registers its listener before the application's own providers boot, so a listener the application registers runs after it.

## Alerts

Every five minutes the scheduler runs `nightwatch-cap:report-alerts`, which reports the month's count to Nightwatch as an unhandled exception once it reaches a threshold:

| Count | Exception |
| --- | --- |
| 80% of the limit | `Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching` |
| The limit | `Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached` |

```text
acme-app has sent 8,000,000 Nightwatch events for 2026-11, past its limit of 8,000,000, and sends nothing more until the month rolls over. Raise nightwatch.monthly_event_limit or lower a sample rate to resume.
```

- **Each month opens its own issue.** The exception code is the month (`202611`), and Nightwatch groups exceptions by class, code, file and line. A new issue reaches the channels the application's Nightwatch notifications go to.
- **An alert goes out past the limit.** Nightwatch sends an unhandled exception at once, in a batch of its own. The cap lets a batch that holds only one alert through, uncounted, and drops a batch that carries anything else.
- **An alert is reported on three runs.** Nightwatch confirms nothing about a report, so the first three runs past a threshold each report it, and the repeats land in the same issue. The limit reached is reported even when the 80% alert was not.
- **The runs themselves cost no events.** The schedule entry samples them at zero, and a run that reports samples only the report.

An application that runs no scheduler gets no alerts. A threshold crossed in the last five minutes of a month is not reported, since the next run reads the new month's count.

## Configuration

Set the limit in `config/nightwatch.php`, next to Nightwatch's own keys:

```php
'monthly_event_limit' => (int) env('NIGHTWATCH_MONTHLY_EVENT_LIMIT', 1_000_000),
'event_limit_cache_store' => env('NIGHTWATCH_EVENT_LIMIT_CACHE_STORE'),
```

| Key | Default | Effect |
| --- | --- | --- |
| `monthly_event_limit` | `1000000` | Events a calendar month before the package drops batches |
| `event_limit_cache_store` | not set | The cache store that holds the count. Without the key, the count lives in the store Laravel's rate limiter uses (`cache.limiter`, else the default store). With the key set to `null`, it lives in the default store. |

Web servers, queue workers and scheduled commands must all increment the same count. Use a store they share, such as `database` or Redis. An `array` store keeps a count per process, and a `file` store a count per server, so neither enforces one budget for the application.

## License

MIT. See [LICENSE](LICENSE).
