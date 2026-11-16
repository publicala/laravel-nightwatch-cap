<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Exceptions;

use RuntimeException;

/**
 * Nightwatch groups exceptions by class, code, file and line, so the month in
 * the code opens a new issue each month the application reaches its limit.
 */
final class MonthlyEventLimitReached extends RuntimeException
{
    public function __construct(string $application, string $month, int $events, int $limit)
    {
        parent::__construct(sprintf(
            "%s has counted %s Nightwatch events for %s, reaching its limit of %s, and sends nothing more until the month rolls over. To resume before then, raise nightwatch.monthly_event_limit above the count and restart the application's workers.",
            $application,
            number_format($events),
            $month,
            number_format($limit),
        ), (int) str_replace('-', '', $month));
    }
}
