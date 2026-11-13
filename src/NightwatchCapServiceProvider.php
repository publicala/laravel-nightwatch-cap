<?php

declare(strict_types=1);

namespace Publicala\NightwatchCap;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Events\IngestingEvents;

final class NightwatchCapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(Dispatcher::class)->listen(IngestingEvents::class, MonthlyEventCap::class);
    }
}
