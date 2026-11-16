<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap\Console;

use Illuminate\Config\Repository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\State\CommandState;
use Laravel\Nightwatch\State\RequestState;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached;
use Publicala\NightwatchCap\MonthlyEventCounter;
use RuntimeException;
use Throwable;

final class ReportCapAlerts extends Command
{
    /**
     * Nightwatch confirms nothing about a report, so each alert goes out on this
     * many runs, five minutes apart. The repeats land in the same issue.
     */
    public const int REPORTS_PER_ALERT = 3;

    protected $signature = 'nightwatch-cap:report-alerts';

    protected $description = 'Report to Nightwatch an application that has counted 80% or all of its monthly events';

    public function handle(MonthlyEventCounter $counter, Repository $config): void
    {
        if (! $this->laravel->bound(Core::class)) {
            return;
        }

        /** @var Core<RequestState|CommandState> $nightwatch */
        $nightwatch = $this->laravel->make(Core::class);
        $sampling = $nightwatch->sampling();

        // The scheduler runs the command in a process of its own, unsampled. Called
        // from a request or job instead, it hands back the sampling it found.
        try {
            $nightwatch->dontSample();

            $this->reportDueAlert($counter, $nightwatch, $config);
        } finally {
            $sampling ? $nightwatch->sample() : $nightwatch->dontSample();
        }
    }

    /**
     * @param  Core<RequestState|CommandState>  $nightwatch
     */
    private function reportDueAlert(MonthlyEventCounter $counter, Core $nightwatch, Repository $config): void
    {
        if (! $nightwatch->enabled()) {
            return;
        }

        try {
            $limit = $counter->limit();
        } catch (Throwable $throwable) {
            $this->skip('invalid_limit', $throwable);

            return;
        }

        try {
            [$alert, $report] = $this->dueAlert($counter, $config, $limit) ?? [null, null];
        } catch (Throwable $throwable) {
            $this->skip('counter_unavailable', $throwable);

            return;
        }

        if (! $alert instanceof RuntimeException) {
            return;
        }

        $nightwatch->sample();
        $nightwatch->ingest->shouldDigestWhenBufferIsFull(false);
        $nightwatch->ignore(fn () => $nightwatch->report($alert, handled: false));
        $nightwatch->dontSample();

        Log::warning('nightwatch.cap_alert_reported', [
            'alert' => $alert::class,
            'report' => $report,
            'message' => $alert->getMessage(),
        ]);
    }

    /**
     * The alert for the month's count and the number of its report, unless this
     * five minutes' report already went out from another process, or the alert
     * went out on every report it gets.
     *
     * @return array{RuntimeException, int}|null
     */
    private function dueAlert(MonthlyEventCounter $counter, Repository $config, int $limit): ?array
    {
        $month = $counter->month();
        $events = $counter->count($month);
        $application = $config->get('app.name');
        $application = is_string($application) ? $application : 'Laravel';

        $alert = match (true) {
            $events >= $limit => new MonthlyEventLimitReached($application, $month, $events, $limit),
            $events >= $limit - intdiv($limit, 5) => new MonthlyEventLimitApproaching($application, $month, $events, $limit),
            default => null,
        };

        if ($alert === null) {
            return null;
        }

        $report = $counter->recordAlert($month, class_basename($alert), $limit);

        return $report === null || $report > self::REPORTS_PER_ALERT ? null : [$alert, $report];
    }

    private function skip(string $reason, Throwable $throwable): void
    {
        Log::warning('nightwatch.cap_alert_skipped', [
            'reason' => $reason,
            'exception' => $throwable->getMessage(),
        ]);
    }
}
