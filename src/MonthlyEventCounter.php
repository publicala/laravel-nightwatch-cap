<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;

/**
 * The count of the events an application sent to Nightwatch in a calendar month,
 * under nightwatch-events:Y-m, and the limit it is held to.
 */
final readonly class MonthlyEventCounter
{
    public const int DEFAULT_LIMIT = 1_000_000;

    private const int DECAY_SECONDS = 60 * 60 * 24 * 31;

    private const int ALERT_DECAY_SECONDS = 60 * 60 * 24 * 32;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
        //
    }

    public function limit(): int
    {
        return $this->config->integer('nightwatch.monthly_event_limit', self::DEFAULT_LIMIT);
    }

    /**
     * Add events to the current month's count and return the new count.
     */
    public function add(int $events): int
    {
        return $this->limiter()->increment(
            key: $this->key(Date::now()->format('Y-m')),
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
     * Record one more report of an alert for a month, in the store that holds the
     * count, and return how many reports it has had.
     */
    public function recordAlert(string $month, string $alert): int
    {
        return $this->limiter()->increment(
            key: sprintf('nightwatch-cap-alert:%s:%s', $month, $alert),
            decaySeconds: self::ALERT_DECAY_SECONDS,
        );
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
