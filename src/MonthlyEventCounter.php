<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The count of the events an application sent to Nightwatch in a calendar month,
 * under nightwatch-events:Y-m, and the limit it is held to.
 */
final readonly class MonthlyEventCounter
{
    /**
     * A calendar month that holds a clock change runs an hour past 31 days.
     */
    private const int DECAY_SECONDS = 60 * 60 * 24 * 32;

    private const int ALERT_DECAY_SECONDS = 60 * 60 * 24 * 32;

    /**
     * Shorter than the five minutes between scheduled runs, so the next run finds
     * the lock gone despite a few seconds of scheduler jitter.
     */
    private const int ALERT_LOCK_SECONDS = 60 * 4;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
        //
    }

    /**
     * @throws InvalidArgumentException when nightwatch.monthly_event_limit holds
     *                                  anything but a whole number: an int of zero
     *                                  or more, or a string of digits
     */
    public function limit(): int
    {
        $limit = $this->config->get('nightwatch.monthly_event_limit', MonthlyEventCap::DEFAULT_LIMIT);

        return match (true) {
            is_int($limit) && $limit >= 0 => $limit,
            is_string($limit) && ctype_digit($limit) => (int) $limit,
            default => throw new InvalidArgumentException('nightwatch.monthly_event_limit must be a whole number.'),
        };
    }

    /**
     * The current month in the application's timezone, Y-m, which names the count.
     */
    public function month(): string
    {
        $timezone = $this->config->get('app.timezone');

        return Date::now(is_string($timezone) ? $timezone : 'UTC')->format('Y-m');
    }

    /**
     * Add events to the current month's count and return the new count.
     */
    public function add(int $events): int
    {
        return $this->limiter()->increment(
            key: $this->key($this->month()),
            decaySeconds: self::DECAY_SECONDS,
            amount: $events,
        );
    }

    /**
     * The count for a month, Y-m.
     */
    public function count(string $month): int
    {
        return (int) $this->limiter()->attempts($this->key($month));
    }

    /**
     * Record one more report of an alert at a limit, in the store that holds the
     * count, and return how many reports it has had. The first process to record
     * a report holds a lock for four minutes, so every other process that shares
     * the store gets null until it expires.
     */
    public function recordAlert(string $month, string $alert, int $limit): ?int
    {
        $key = sprintf('nightwatch-cap-alert:%s:%s:%d', $month, $alert, $limit);

        if ($this->limiter()->increment($key.':lock', self::ALERT_LOCK_SECONDS) !== 1) {
            return null;
        }

        return $this->limiter()->increment($key, self::ALERT_DECAY_SECONDS);
    }

    private function key(string $month): string
    {
        return sprintf('nightwatch-events:%s', $month);
    }

    /**
     * Without nightwatch.event_limit_cache_store the count lives in the store the
     * application's RateLimiter uses. With it, in that store, or in the default
     * store when the key holds null.
     */
    private function limiter(): RateLimiter
    {
        if (! $this->config->has('nightwatch.event_limit_cache_store')) {
            return $this->container->make(RateLimiter::class);
        }

        $store = $this->config->get('nightwatch.event_limit_cache_store');

        return new RateLimiter(Cache::store(is_string($store) ? $store : null));
    }
}
