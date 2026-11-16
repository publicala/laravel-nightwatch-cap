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
     * many runs, at least four minutes apart. The repeats land in the same issue.
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
        // from a request or job instead, it records nothing of its own and hands
        // back the sampling it found. ignore() restores the sampling it passes to
        // queued jobs.
        $nightwatch->ignore(function () use ($counter, $nightwatch, $config, $sampling): void {
            try {
                $this->reportDueAlert($counter, $nightwatch, $config);
            } finally {
                $sampling ? $nightwatch->sample() : $nightwatch->dontSample();
            }
        });
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

        $month = $counter->month();

        try {
            $alert = $this->dueAlert($counter, $config, $month, $limit);
        } catch (Throwable $throwable) {
            $this->skip('counter_unavailable', $throwable);

            return;
        }

        if (! $alert instanceof RuntimeException) {
            return;
        }

        // A scheduler whose agent is down must not take a report from one whose
        // agent can send it.
        try {
            $nightwatch->ingest->ping();
        } catch (Throwable $throwable) {
            $this->skip('agent_unreachable', $throwable);

            return;
        }

        try {
            $report = $counter->recordAlert($month, class_basename($alert), $limit);
        } catch (Throwable $throwable) {
            $this->skip('counter_unavailable', $throwable);

            return;
        }

        if ($report === null || $report > self::REPORTS_PER_ALERT) {
            return;
        }

        $nightwatch->sample();
        $nightwatch->ingest->shouldDigestWhenBufferIsFull(false);
        $nightwatch->ignore(fn () => $nightwatch->report($alert, handled: false));

        $this->logWarning('nightwatch.cap_alert_reported', [
            'alert' => $alert::class,
            'report' => $report,
            'message' => $alert->getMessage(),
        ]);
    }

    /**
     * The alert the month's count has reached, if any.
     */
    private function dueAlert(MonthlyEventCounter $counter, Repository $config, string $month, int $limit): ?RuntimeException
    {
        $events = $counter->count($month);
        $application = $config->get('app.name');
        $application = is_string($application) ? $application : 'Laravel';

        return match (true) {
            $events >= $limit => new MonthlyEventLimitReached($application, $month, $events, $limit),
            $events >= $limit - intdiv($limit, 5) => new MonthlyEventLimitApproaching($application, $month, $events, $limit),
            default => null,
        };
    }

    private function skip(string $reason, Throwable $throwable): void
    {
        $this->logWarning('nightwatch.cap_alert_skipped', [
            'reason' => $reason,
            'exception' => $throwable->getMessage(),
        ]);
    }

    /**
     * A logger that throws would fail the scheduled run, or the request or job
     * that called the command, so the warning is best effort.
     *
     * @param  array<string, mixed>  $context
     */
    private function logWarning(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            //
        }
    }
}
