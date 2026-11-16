<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Console\Sample;
use Laravel\Nightwatch\Core;
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

        // Nightwatch leaves Core unbound when its own provider fails to register, and
        // Sample::never() resolves Core as soon as the entry is built.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! $this->app->bound(Core::class)) {
                return;
            }

            $schedule->command(ReportCapAlerts::class)->everyFiveMinutes()->tap(Sample::never());
        });
    }
}
