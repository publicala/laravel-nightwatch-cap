<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

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
    public function __construct(private MonthlyEventCounter $counter)
    {
        //
    }

    public function __invoke(IngestingEvents $event): ?false
    {
        $limit = $this->counter->limit();

        // Nightwatch drops the batch, and reports nothing, when this listener
        // throws. A counter that fails lets the batch through uncounted instead.
        try {
            $count = $event->eventCount();
            $ingested = $this->counter->add($count);
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
}
