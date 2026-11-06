<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Cache\RateLimiter;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Events\IngestingEvents;
use Throwable;

/**
 * Nightwatch dispatches IngestingEvents through Event::until(), once per batch it
 * is about to send. Returning false drops the batch. Returning null lets it
 * through and lets later listeners run, where any other value would stop them.
 */
final readonly class MonthlyEventCap
{
    public const int DEFAULT_LIMIT = 1_000_000;

    private const int DECAY_SECONDS = 60 * 60 * 24 * 31;

    public function __construct(
        private Container $container,
        private Repository $config,
    ) {
        //
    }

    public function __invoke(IngestingEvents $event): ?false
    {
        $limit = $this->config->integer('nightwatch.monthly_event_limit', self::DEFAULT_LIMIT);

        // Nightwatch drops the batch, and reports nothing, when this listener
        // throws. A counter that fails lets the batch through uncounted instead.
        try {
            $count = $event->eventCount();
            $ingested = $this->limiter()->increment(
                key: sprintf('nightwatch-events:%s', Date::now()->format('Y-m')),
                decaySeconds: self::DECAY_SECONDS,
                amount: $count,
            );
        } catch (Throwable $throwable) {
            Log::warning('nightwatch.ingest_uncounted', [
                'reason' => 'counter_unavailable',
                'exception' => $throwable->getMessage(),
            ]);

            return null;
        }

        if ($ingested <= $limit) {
            return null;
        }

        if ($ingested - $count <= $limit) {
            Log::warning('nightwatch.ingest_capped', [
                'reason' => 'monthly_event_limit_reached',
                'limit' => $limit,
            ]);
        }

        return false;
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
