<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Exceptions;

use RuntimeException;

/**
 * Nightwatch groups exceptions by class, code, file and line, so the month in
 * the code opens a new issue each month the application crosses the threshold.
 */
final class MonthlyEventLimitApproaching extends RuntimeException
{
    public function __construct(string $application, string $month, int $events, int $limit)
    {
        parent::__construct(sprintf(
            '%s has sent %s of its %s Nightwatch events for %s. Past the limit, it sends nothing more until the month rolls over.',
            $application,
            number_format($events),
            number_format($limit),
            $month,
        ), (int) str_replace('-', '', $month));
    }
}
