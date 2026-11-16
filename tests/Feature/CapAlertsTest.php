<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Events\IngestingEvents;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Publicala\NightwatchCap\Console\ReportCapAlerts;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitApproaching;
use Publicala\NightwatchCap\Exceptions\MonthlyEventLimitReached;
use Publicala\NightwatchCap\Tests\Support\RecordingIngest;

beforeEach(function (): void {
    $this->travelTo('2026-11-15 12:00:00');

    Config::set('app.name', 'acme-app');
    Config::set('nightwatch.enabled', true);
    Config::set('nightwatch.token', 'test-token');
    Config::set('nightwatch.monthly_event_limit', 100);
    $this->app->register(NightwatchServiceProvider::class);

    $this->ingest = new RecordingIngest;
    resolve(Core::class)->ingest = $this->ingest;
});

/**
 * @return list<array<mixed>>
 */
function alertsSent(RecordingIngest $ingest): array
{
    return array_values(array_filter($ingest->sentNow, fn (array $record): bool => $record['t'] === 'exception'));
}

function countEvents(int $events, string $month = '2026-11'): void
{
    RateLimiter::increment("nightwatch-events:{$month}", amount: $events);
}

it('reports nothing under 80% of the limit', function (): void {
    countEvents(79);

    $this->artisan(ReportCapAlerts::class)->assertSuccessful();

    expect(alertsSent($this->ingest))->toBeEmpty();
});

it('reports an unhandled alert at 80% of the limit', function (): void {
    countEvents(80);

    $this->artisan(ReportCapAlerts::class)->assertSuccessful();

    expect(alertsSent($this->ingest))->toHaveCount(1)
        ->and(alertsSent($this->ingest)[0])->toMatchArray([
            'class' => MonthlyEventLimitApproaching::class,
            'code' => '202611',
            'handled' => false,
            'message' => 'acme-app has sent 80 of its 100 Nightwatch events for 2026-11. Past the limit, it sends nothing more until the month rolls over.',
        ]);
});

it('alerts at no fewer than 80% of a limit that 5 does not divide', function (): void {
    Config::set('nightwatch.monthly_event_limit', 101);
    countEvents(80);
    $this->artisan(ReportCapAlerts::class);

    expect(alertsSent($this->ingest))->toBeEmpty();

    countEvents(1);
    $this->artisan(ReportCapAlerts::class);

    expect(array_column(alertsSent($this->ingest), 'class'))->toBe([MonthlyEventLimitApproaching::class]);
});

it('reports nothing before the first event under a limit of one', function (): void {
    Config::set('nightwatch.monthly_event_limit', 1);

    $this->artisan(ReportCapAlerts::class);

    expect(alertsSent($this->ingest))->toBeEmpty();
});

it('reads a count the store returns as a numeric string', function (): void {
    RateLimiter::shouldReceive('attempts')->with('nightwatch-events:2026-11')->andReturn('80');
    RateLimiter::shouldReceive('increment')->andReturn(1);

    $this->artisan(ReportCapAlerts::class)->assertSuccessful();

    expect(array_column(alertsSent($this->ingest), 'class'))->toBe([MonthlyEventLimitApproaching::class]);
});

it('names the application Laravel when app.name is not a string', function (): void {
    Config::set('app.name');
    countEvents(80);

    $this->artisan(ReportCapAlerts::class);

    expect(alertsSent($this->ingest)[0]['message'])->toStartWith('Laravel has sent 80 ');
});

it('skips the run when the counter fails', function (string $failing): void {
    $refused = new RuntimeException('Connection refused');
    RateLimiter::shouldReceive('attempts')->andReturnUsing(fn (): int => $failing === 'attempts' ? throw $refused : 100);
    RateLimiter::shouldReceive('increment')->andThrow($refused);

    Log::shouldReceive('warning')
        ->once()
        ->with('nightwatch.cap_alert_skipped', [
            'reason' => 'counter_unavailable',
            'exception' => 'Connection refused',
        ]);

    $this->artisan(ReportCapAlerts::class)->assertSuccessful();

    expect(alertsSent($this->ingest))->toBeEmpty();
})->with(['attempts', 'increment']);

it('keeps the runs an alert gets while Nightwatch is disabled', function (): void {
    countEvents(100);
    $nightwatch = resolve(Core::class);

    $nightwatch->config['enabled'] = false;
    foreach (range(1, ReportCapAlerts::REPORTS_PER_ALERT) as $run) {
        $this->artisan(ReportCapAlerts::class)->assertSuccessful();
    }

    $nightwatch->config['enabled'] = true;
    $this->artisan(ReportCapAlerts::class);

    expect(alertsSent($this->ingest))->toHaveCount(1);
});

it('records nothing and samples no job while the application decides whether to report the alert', function (): void {
    $seen = [];
    $this->app->instance(ExceptionHandler::class, new class($this->app, function () use (&$seen): void {
        $seen = [
            'paused' => resolve(Core::class)->paused(),
            'sampling in context' => Context::getHidden('nightwatch_should_sample'),
            'digests when the buffer is full' => $this->ingest->digestsWhenBufferIsFull,
        ];
    }) extends Handler
    {
        public function __construct($container, private readonly Closure $observe)
        {
            parent::__construct($container);
        }

        public function shouldReport(Throwable $e): bool
        {
            ($this->observe)();

            return parent::shouldReport($e);
        }
    });
    countEvents(100);

    $this->artisan(ReportCapAlerts::class);

    expect($seen)->toBe([
        'paused' => true,
        'sampling in context' => false,
        'digests when the buffer is full' => false,
    ])->and(alertsSent($this->ingest))->toHaveCount(1);
});

it('reports the limit reached in place of the 80% alert', function (): void {
    countEvents(130);

    $this->artisan(ReportCapAlerts::class)->assertSuccessful();

    expect(alertsSent($this->ingest))->toHaveCount(1)
        ->and(alertsSent($this->ingest)[0])->toMatchArray([
            'class' => MonthlyEventLimitReached::class,
            'code' => '202611',
            'handled' => false,
            'message' => 'acme-app has sent 130 Nightwatch events for 2026-11, past its limit of 100, and sends nothing more until the month rolls over. Raise nightwatch.monthly_event_limit above the count to resume before then.',
        ]);
});

it('reports each alert on three runs, then stops', function (): void {
    countEvents(100);

    foreach (range(1, 5) as $run) {
        $this->artisan(ReportCapAlerts::class)->assertSuccessful();
    }

    expect(alertsSent($this->ingest))->toHaveCount(ReportCapAlerts::REPORTS_PER_ALERT)
        ->and(array_unique(array_column(alertsSent($this->ingest), '_group')))->toHaveCount(1);
});

it('reports the limit reached even after the 80% alert ran out of reports', function (): void {
    countEvents(80);

    foreach (range(1, 4) as $run) {
        $this->artisan(ReportCapAlerts::class);
    }

    countEvents(20);
    $this->artisan(ReportCapAlerts::class);

    expect(array_column(alertsSent($this->ingest), 'class'))->toBe([
        MonthlyEventLimitApproaching::class,
        MonthlyEventLimitApproaching::class,
        MonthlyEventLimitApproaching::class,
        MonthlyEventLimitReached::class,
    ]);
});

it('codes each month apart, so each month opens its own issue', function (): void {
    countEvents(100);
    $this->artisan(ReportCapAlerts::class);

    $this->travelTo('2026-12-02 00:00:00');
    countEvents(100, '2026-12');
    $this->artisan(ReportCapAlerts::class);

    expect(array_column(alertsSent($this->ingest), 'code'))->toBe(['202611', '202612'])
        ->and(array_unique(array_column(alertsSent($this->ingest), '_group')))->toHaveCount(2);
});

it('discards the rest of the run after reporting', function (): void {
    countEvents(100);

    $this->artisan(ReportCapAlerts::class);
    resolve(Core::class)->finishExecution();

    expect(resolve(Core::class)->sampling())->toBeFalse()
        ->and($this->ingest->buffered)->toBeEmpty()
        ->and(alertsSent($this->ingest))->toHaveCount(1);
});

it('runs every five minutes without sampling the run itself', function (): void {
    $event = collect(resolve(Schedule::class)->events())
        ->firstOrFail(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'nightwatch-cap:report-alerts'));

    $nightwatch = resolve(Core::class);
    $nightwatch->config['sampling']['scheduled_tasks'] = 1.0;
    $nightwatch->configureScheduledTaskSampling($event);

    expect($event->expression)->toBe('*/5 * * * *')
        ->and($nightwatch->sampling())->toBeFalse();
});

it('schedules nothing outside the console, and still caps', function (): void {
    $_SERVER['APP_RUNNING_IN_CONSOLE'] = 'false';
    $this->refreshApplication();

    expect($this->app->runningInConsole())->toBeFalse()
        ->and(resolve(Schedule::class)->events())->toBeEmpty()
        ->and(resolve(Dispatcher::class)->getListeners(IngestingEvents::class))->toHaveCount(1);
})->after(function (): void {
    unset($_SERVER['APP_RUNNING_IN_CONSOLE']);
});

it('lets an alert through past the limit, uncounted', function (): void {
    countEvents(500);
    $this->artisan(ReportCapAlerts::class);

    expect(Event::until(new IngestingEvents(alertsSent($this->ingest))))->toBeNull()
        ->and(RateLimiter::attempts('nightwatch-events:2026-11'))->toBe(500);
});

it('drops a batch past the limit that carries an alert among other records', function (): void {
    countEvents(500);
    $this->artisan(ReportCapAlerts::class);

    expect(Event::until(new IngestingEvents([...alertsSent($this->ingest), ['t' => 'query']])))->toBeFalse();
});

it('drops another exception past the limit', function (): void {
    countEvents(500);

    expect(Event::until(new IngestingEvents([['t' => 'exception', 'class' => RuntimeException::class]])))->toBeFalse();
});
