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
