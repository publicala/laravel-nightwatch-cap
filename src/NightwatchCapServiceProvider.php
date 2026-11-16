<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Console\Sample;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Events\IngestingEvents;
use Publicala\NightwatchCap\Console\ReportCapAlerts;

final class NightwatchCapServiceProvider extends ServiceProvider
{
    /**
     * Prime minutes past the hour, clear of :00 and every five-minute mark, where
     * other scheduled tasks cluster.
     */
    private const array ALERT_MINUTES = [7, 11, 13, 17, 19, 23, 29, 31, 37, 41, 43, 47, 53, 59];

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

            $schedule->command(ReportCapAlerts::class)->hourlyAt($this->alertMinute())->tap(Sample::never());
        });
    }

    /**
     * The application's name picks its minute, so the applications that share a
     * host or a Nightwatch organization do not all run the command at once. The
     * mask keeps the hash's low 28 bits, which are the same on 32- and 64-bit PHP.
     *
     * @return int<7, 59>
     */
    private function alertMinute(): int
    {
        $application = $this->app->make(Repository::class)->get('app.name');

        return self::ALERT_MINUTES[(crc32(is_string($application) ? $application : 'Laravel') & 0x0FFFFFFF) % count(self::ALERT_MINUTES)];
    }
}
