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
    private const int DECAY_SECONDS = 60 * 60 * 24 * 31;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
        //
    }

    public function limit(): int
    {
        return $this->config->integer('nightwatch.monthly_event_limit', MonthlyEventCap::DEFAULT_LIMIT);
    }

    /**
     * Add events to the current month's count and return the new count.
     */
    public function add(int $events): int
    {
        return $this->limiter()->increment(
            key: sprintf('nightwatch-events:%s', Date::now()->format('Y-m')),
            decaySeconds: self::DECAY_SECONDS,
            amount: $events,
        );
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
