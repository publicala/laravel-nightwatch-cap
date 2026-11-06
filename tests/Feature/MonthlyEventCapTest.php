<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter as RateLimiterFacade;
use Laravel\Nightwatch\Events\IngestingEvents;
use Publicala\NightwatchCap\MonthlyEventCap;

/**
 * @param  list<string>  $types
 */
function batch(array $types): IngestingEvents
{
    return new IngestingEvents(array_map(fn (string $type): array => ['t' => $type], $types));
}

function requests(int $count): IngestingEvents
{
    return batch(array_fill(0, $count, 'request'));
}

beforeEach(function (): void {
    $this->travelTo('2026-11-15 12:00:00');
});

it('lets a batch under the monthly limit through', function (): void {
    Config::set('nightwatch.monthly_event_limit', 10);

    expect(Event::until(requests(9)))->toBeNull();
});

it('lets the batch landing exactly on the limit through', function (): void {
    Config::set('nightwatch.monthly_event_limit', 10);

    expect(Event::until(requests(4)))->toBeNull()
        ->and(Event::until(requests(6)))->toBeNull();
});

it('cancels the batch that carries the count past the limit, and every batch after it', function (): void {
    Config::set('nightwatch.monthly_event_limit', 10);

    expect(Event::until(requests(10)))->toBeNull()
        ->and(Event::until(requests(1)))->toBeFalse()
        ->and(Event::until(requests(1)))->toBeFalse();
});

it('does not count user records against the limit', function (): void {
    Config::set('nightwatch.monthly_event_limit', 1);

    expect(Event::until(batch(['request', 'user', 'user'])))->toBeNull()
        ->and(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(1);
});

it('caps at one million events a month when the application sets no limit', function (): void {
    RateLimiterFacade::increment('nightwatch-events:2026-11', amount: MonthlyEventCap::DEFAULT_LIMIT - 1);

    expect(Config::has('nightwatch.monthly_event_limit'))->toBeFalse()
        ->and(Event::until(requests(1)))->toBeNull()
        ->and(Event::until(requests(1)))->toBeFalse();
});

it('warns once, on the batch that crosses the limit', function (): void {
    Config::set('nightwatch.monthly_event_limit', 1);

    Log::shouldReceive('warning')
        ->once()
        ->with('nightwatch.ingest_capped', [
            'reason' => 'monthly_event_limit_reached',
            'limit' => 1,
        ]);

    Event::until(requests(2));
    Event::until(requests(1));
});

it('counts each calendar month under its own key', function (): void {
    Config::set('nightwatch.monthly_event_limit', 10);

    Event::until(requests(10));
    $this->travelTo('2026-12-01 00:00:00');

    expect(Event::until(requests(10)))->toBeNull()
        ->and(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(10)
        ->and(RateLimiterFacade::attempts('nightwatch-events:2026-12'))->toBe(10);
});

it('rolls the month over in the application timezone', function (): void {
    Config::set('app.timezone', 'America/Argentina/Buenos_Aires');
    date_default_timezone_set('America/Argentina/Buenos_Aires');
    $this->travelTo('2026-11-30 22:00:00');

    Event::until(requests(1));

    expect(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(1);
})->after(fn (): bool => date_default_timezone_set('UTC'));

it('keeps counting from the value already in the store', function (): void {
    Config::set('nightwatch.monthly_event_limit', 10);
    RateLimiterFacade::increment('nightwatch-events:2026-11', amount: 9);

    expect(Event::until(requests(1)))->toBeNull()
        ->and(Event::until(requests(1)))->toBeFalse();
});

it('keeps the count for 31 days', function (): void {
    Event::until(requests(3));

    $this->travel(31 * 24 * 60 * 60 - 1)->seconds();
    expect(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(3);

    $this->travel(1)->seconds();
    expect(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(0);
});

it('counts through the application rate limiter when no store is configured', function (): void {
    RateLimiterFacade::shouldReceive('increment')
        ->once()
        ->with('nightwatch-events:2026-11', 60 * 60 * 24 * 31, 2)
        ->andReturn(2);

    expect(Event::until(requests(2)))->toBeNull();
});

it('counts in the configured store', function (): void {
    Config::set('cache.stores.counter', ['driver' => 'array']);
    Config::set('nightwatch.event_limit_cache_store', 'counter');

    Event::until(requests(3));

    expect(new RateLimiter(Cache::store('counter'))->attempts('nightwatch-events:2026-11'))->toBe(3)
        ->and(RateLimiterFacade::attempts('nightwatch-events:2026-11'))->toBe(0);
});

it('counts in the default store when the configured store is null', function (): void {
    Config::set('nightwatch.event_limit_cache_store');

    $default = Cache::driver();
    Cache::shouldReceive('store')->once()->with(null)->andReturn($default);

    expect(Event::until(requests(1)))->toBeNull();
});

it('lets the batch through uncounted when the counter fails', function (): void {
    RateLimiterFacade::shouldReceive('increment')->andThrow(new RuntimeException('Connection refused'));

    Log::shouldReceive('warning')
        ->once()
        ->with('nightwatch.ingest_uncounted', [
            'reason' => 'counter_unavailable',
            'exception' => 'Connection refused',
        ]);

    expect(Event::until(requests(1)))->toBeNull();
});

it('lets the batch through uncounted when the configured store is not defined', function (): void {
    Config::set('nightwatch.event_limit_cache_store', 'missing');

    Log::shouldReceive('warning')
        ->once()
        ->with('nightwatch.ingest_uncounted', [
            'reason' => 'counter_unavailable',
            'exception' => 'Cache store [missing] is not defined.',
        ]);

    expect(Event::until(requests(1)))->toBeNull();
});

it('lets the listeners registered after it run under the limit', function (): void {
    Event::listen(IngestingEvents::class, fn (): false => false);

    expect(Event::until(requests(1)))->toBeFalse();
});

it('registers the listener once', function (): void {
    expect(resolve(Dispatcher::class)->getListeners(IngestingEvents::class))->toHaveCount(1);
});
