<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached;
use Publicala\NightwatchCap\MonthlyEventCounter;

final class ReportCapAlerts extends Command
{
    /**
     * Nightwatch confirms nothing about a report, so each alert goes out on this
     * many runs. The repeats land in the same issue.
     */
    public const int REPORTS_PER_ALERT = 3;

    protected $signature = 'nightwatch-cap:report-alerts';

    protected $description = 'Report to Nightwatch an application that has sent 80% or all of its monthly events';

    /**
     * @param  Core<RequestState|CommandState>  $nightwatch
     */
    public function handle(MonthlyEventCounter $counter, Core $nightwatch, Repository $config): void
    {
        $nightwatch->dontSample();

        $month = Date::now()->format('Y-m');
        $limit = $counter->limit();
        $events = $counter->count($month);
        $application = $config->string('app.name', 'Laravel');

        $alert = match (true) {
            $events >= $limit => new MonthlyEventLimitReached($application, $month, $events, $limit),
            $events >= intdiv($limit * 4, 5) => new MonthlyEventLimitApproaching($application, $month, $events, $limit),
            default => null,
        };

        if ($alert === null || $counter->recordAlert($month, class_basename($alert)) > self::REPORTS_PER_ALERT) {
            return;
        }

        $nightwatch->sample();

        try {
            $nightwatch->report($alert, handled: false);
        } finally {
            $nightwatch->dontSample();
        }
    }
}
