<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Events\IngestingEvents;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached;
use Throwable;

/**
 * Nightwatch dispatches IngestingEvents through Event::until(), once per batch it
 * is about to send. Returning false drops the batch. Returning null lets it
 * through and lets later listeners run, where any other value would stop them.
 */
final readonly class MonthlyEventCap
{
    public const int DEFAULT_LIMIT = 1_000_000;

    public function __construct(private MonthlyEventCounter $counter)
    {
        //
    }

    public function __invoke(IngestingEvents $event): ?false
    {
        if ($this->isCapAlert($event)) {
            return null;
        }

        // Nightwatch drops the batch, and reports nothing, when this listener
        // throws. A limit or counter that fails lets the batch through uncounted.
        try {
            $limit = $this->counter->limit();
        } catch (Throwable $throwable) {
            return $this->letThroughUncounted('invalid_limit', $throwable);
        }

        try {
            $count = $event->eventCount();
            $ingested = $this->counter->add($count);
        } catch (Throwable $throwable) {
            return $this->letThroughUncounted('counter_unavailable', $throwable);
        }

        if ($ingested <= $limit) {
            return null;
        }

        if ($ingested - $count <= $limit) {
            $this->warn('nightwatch.ingest_capped', [
                'reason' => 'monthly_event_limit_reached',
                'limit' => $limit,
            ]);
        }

        return false;
    }

    private function letThroughUncounted(string $reason, Throwable $throwable): null
    {
        $this->warn('nightwatch.ingest_uncounted', [
            'reason' => $reason,
            'exception' => $throwable->getMessage(),
        ]);

        return null;
    }

    /**
     * A logger that throws would escape the listener, and Nightwatch would drop the
     * batch, so the warning is best effort.
     *
     * @param  array<string, mixed>  $context
     */
    private function warn(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            //
        }
    }

    /**
     * A cap alert is reported on its own, as an unhandled exception that Nightwatch
     * sends at once in a batch of one. It goes out past the limit, uncounted.
     */
    private function isCapAlert(IngestingEvents $event): bool
    {
        if (count($event->records) !== 1) {
            return false;
        }

        $record = $event->records[0];

        return ($record['t'] ?? null) === 'exception'
            && in_array($record['class'] ?? null, [MonthlyEventLimitApproaching::class, MonthlyEventLimitReached::class], true);
    }
}
