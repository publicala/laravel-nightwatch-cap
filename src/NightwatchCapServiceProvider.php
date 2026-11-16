<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Console\Sample;
use Laravel\Nightwatch\Events\IngestingEvents;
use Publicala\NightwatchCap\Console\ReportCapAlerts;

final class NightwatchCapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Dispatcher::class)->listen(IngestingEvents::class, MonthlyEventCap::class);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([ReportCapAlerts::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(ReportCapAlerts::class)->everyFiveMinutes()->tap(Sample::never());
        });
    }
}
