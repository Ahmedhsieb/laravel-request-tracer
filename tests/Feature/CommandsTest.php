<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Span;
use Ahmedhsieb\RequestTracer\SpanType;
use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\TraceData;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

function storeTrace(string $id, $createdAt): void
{
    $spans = [new Span(1, null, SpanType::Request, 'GET /x', null, null, 0)];
    app(TraceStorage::class)->store(new TraceData([
        'id' => $id, 'kind' => 'request', 'method' => 'GET', 'path' => '/x', 'route_name' => null, 'status' => 200,
        'duration_ms' => 1.0, 'memory_peak' => 1, 'user_id' => null, 'tenant' => null, 'module' => null, 'ip_hash' => null,
        'spans_count' => 1, 'queries_count' => 0, 'problems_count' => 0, 'tables_written' => null, 'tables_read' => null, 'notes' => null,
        'truncated' => false, 'laravel_version' => '12', 'environment' => 'testing', 'mode' => 'always', 'parent_trace_id' => null,
        'created_at' => $createdAt,
    ], $spans));
}

it('prunes by age', function () {
    storeTrace('01h0000000000000000000000a', now()->subHours(30));
    storeTrace('01h0000000000000000000000b', now()->subHours(2));
    storeTrace('01h0000000000000000000000c', now());

    $this->artisan('request-tracer:prune', ['--hours' => 24])->assertSuccessful();

    expect($this->tracerTable('traces')->pluck('id')->all())->toBe(['01h0000000000000000000000b', '01h0000000000000000000000c']);
});

it('prunes by count and uses the configured retention by default', function () {
    config(['request-tracer.retention.hours' => 1, 'request-tracer.retention.max_rows' => 1]);
    storeTrace('01h0000000000000000000000a', now()->subMinutes(10));
    storeTrace('01h0000000000000000000000b', now()->subMinutes(5));
    storeTrace('01h0000000000000000000000c', now());

    $this->artisan('request-tracer:prune')->assertSuccessful();

    expect($this->tracerTable('traces')->pluck('id')->all())->toBe(['01h0000000000000000000000c']);
});

it('schedules the prune command automatically', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'request-tracer:prune'));

    expect($events)->toHaveCount(1)->and($events->first()->expression)->toBe('0 * * * *');            // hourly
});

it('can leave the schedule to the application', function () {
    // the provider registers the event when Schedule is resolved; with the option off nothing may be added
    config(['request-tracer.retention.schedule' => false]);
    app()->forgetInstance(Schedule::class);

    $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains((string) $e->command, 'request-tracer:prune'));

    expect($events)->toHaveCount(0);
});

it('issues a signed token from the command line', function () {
    $this->artisan('request-tracer:token', ['--ttl' => 90, '--route' => 'api/*'])
        ->expectsOutputToContain('Send it as the "X-Request-Tracer" header')
        ->assertSuccessful();
});

it('publishes config, migrations and assets under their tags', function () {
    $tags = ServiceProvider::publishableGroups();

    expect($tags)->toContain('request-tracer-config')->toContain('request-tracer-migrations')->toContain('request-tracer-assets');
});
