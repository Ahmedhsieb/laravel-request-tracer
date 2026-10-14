<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Facades\Tracer as TracerFacade;
use Ahmedhsieb\RequestTracer\Hot;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Note;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\User;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// -------------------------------------------------------------------------------------------- caps and collapsing

it('merges identical statements beyond the collapse limit into one counted step and does not mark the trace truncated', function () {
    config(['request-tracer.limits.collapse_after' => 5]);
    Route::get('/many', function () {
        foreach (range(1, 60) as $i) {
            User::query()->count();
        }

        return 'ok';
    });

    $this->get('/many')->assertOk();

    $trace = $this->lastTrace();
    $queries = array_values(array_filter($this->traceSpans(), fn (array $s) => $s['type'] === 'query'));
    expect($trace['queries_count'])->toBe(60)->and($trace['truncated'])->toBeFalse()
        ->and(count($queries))->toBeLessThanOrEqual(6)
        ->and(max(array_column($queries, 'count')))->toBeGreaterThan(50);
});

it('stops recording new steps at the span cap, says so, and the request still succeeds', function () {
    config(['request-tracer.limits.max_spans' => 20, 'request-tracer.limits.collapse_after' => 1000]);
    Route::get('/flood', function () {
        foreach (range(1, 100) as $i) {
            DB::select('select '.$i.' as n');                  // all different, so nothing collapses
        }

        return 'still fine';
    });

    $this->get('/flood')->assertOk()->assertSee('still fine');

    $trace = $this->lastTrace();
    expect($trace['truncated'])->toBeTrue()
        ->and($trace['spans_count'])->toBeLessThanOrEqual(40)
        ->and($trace['queries_count'])->toBe(100);              // counting continues after the cap
});

it('has no span cap when it is unlimited, and the request and the trace both succeed', function () {
    config(['request-tracer.unlimited' => true, 'request-tracer.limits.max_spans' => 20, 'request-tracer.limits.collapse_after' => 1000]);
    Route::get('/flood-all', function () {
        foreach (range(1, 100) as $i) {
            DB::select('select '.$i.' as n');
        }

        return 'still fine';
    });

    $this->get('/flood-all')->assertOk()->assertSee('still fine');

    $trace = $this->lastTrace();
    $queries = array_values(array_filter($this->traceSpans(), fn (array $s) => $s['type'] === 'query'));
    expect($trace['truncated'])->toBeFalse()
        ->and(count($queries))->toBe(100);                      // every one is stored, although max_spans says 20
});

it('stores a response of any size in full when it is unlimited', function () {
    config(['request-tracer.unlimited' => true, 'request-tracer.dumper.max_span_bytes' => 2048, 'request-tracer.dumper.max_depth' => 1]);
    Route::get('/big-json', fn () => response()->json(['rows' => array_map(fn ($i) => ['n' => $i, 'text' => str_repeat('y', 500), 'deep' => ['a' => ['b' => 'bottom']]], range(1, 200))]));

    $this->get('/big-json')->assertOk();

    $stored = json_encode($this->traceSpans());
    expect($stored)->toContain('bottom')
        ->and(strlen((string) $stored))->toBeGreaterThan(100_000);
});

it('splits the inserts by size, so large payloads cannot go over the packet limit of the database', function () {
    config(['request-tracer.unlimited' => true, 'request-tracer.storage.insert_max_bytes' => 65536, 'request-tracer.limits.collapse_after' => 1000]);
    Route::get('/fat', function () {
        foreach (range(1, 8) as $i) {
            DB::select('select ? as blob'.$i, [str_repeat('z', 90_000)]);
        }

        return 'ok';
    });

    $this->get('/fat')->assertOk();

    $queries = array_values(array_filter($this->traceSpans(), fn (array $s) => $s['type'] === 'query'));
    expect(count($queries))->toBe(8)
        ->and(strlen((string) json_encode($queries[7]['input'] ?? '')))->toBeGreaterThan(80_000);
});

// -------------------------------------------------------------------------------------------- queued writes

it('hands the trace to the queue instead of writing it in terminate(), and a worker stores it', function () {
    useDatabaseQueue();
    config(['request-tracer.storage.queue.enabled' => true]);
    Route::get('/queued', fn () => User::query()->count());

    $this->get('/queued')->assertOk();

    expect($this->tracerTable('traces')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertExitCode(0);

    expect($this->tracerTable('traces')->count())->toBe(1)               // stored once, and the worker did not record itself
        ->and($this->lastTrace())->toMatchArray(['kind' => 'request', 'path' => '/queued']);
});

// -------------------------------------------------------------------------------------------- long-lived workers (Octane)

it('leaves no state behind between requests: flags, session and memory', function () {
    Route::get('/ok', fn () => User::query()->count());
    Route::get('/boom', function () {
        User::query()->count();
        throw new RuntimeException('boom');
    });
    Route::get('/not-recorded', fn () => 'x');
    config(['request-tracer.exclude.paths' => ['not-recorded']]);

    $tracer = app(Tracer::class);
    $spanCounts = [];
    $memory = [];
    foreach (range(1, 60) as $i) {
        $path = ['/ok', '/boom', '/not-recorded'][$i % 3];
        $this->get($path);

        expect(Hot::$on)->toBeFalse()->and($tracer->on)->toBeFalse()->and($tracer->session())->toBeNull();
        if ($i === 10 || $i === 58) {
            gc_collect_cycles();
            $memory[$i] = memory_get_usage();
        }
    }

    $rows = $this->traceStorage()->traces([], null, 100)['data'];
    expect($rows)->toHaveCount(40);                                      // /ok and /boom, never /not-recorded
    foreach ($rows as $row) {
        $spanCounts[$row['path']][] = $row['spans_count'];
        expect($row['queries_count'])->toBe(1);                          // no query of an earlier request was counted again
    }
    expect(count(array_unique($spanCounts['/ok'])))->toBe(1)             // the 40th request has as many steps as the first
        ->and($memory[58] - $memory[10])->toBeLessThan(8 * 1024 * 1024);
});

// -------------------------------------------------------------------------------------------- tenant, module, several databases

it('stores the tenant and module that the resolvers return, and survives a resolver that throws', function () {
    Route::get('/ctx', fn () => 'ok');
    TracerFacade::resolveTenantUsing(fn () => 'acme');
    TracerFacade::resolveModuleUsing(fn () => throw new RuntimeException('resolver bug'));

    $this->get('/ctx')->assertOk()->assertSee('ok');

    expect($this->lastTrace())->toMatchArray(['tenant' => 'acme', 'module' => null]);
    TracerFacade::resolveTenantUsing(null);
    TracerFacade::resolveModuleUsing(null);
});

it('attributes writes to the connection that received them', function () {
    config(['database.connections.other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    Schema::connection('other')->create('notes', function (Blueprint $table) {
        $table->id();
        $table->string('body');
        $table->timestamps();
    });
    Route::post('/two-databases', function () {
        User::create(['name' => 'Zed', 'email' => 'zed@example.test', 'password' => 'x', 'api_token' => 't']);
        Note::create(['body' => 'hello']);

        return 'ok';
    });

    $this->post('/two-databases')->assertOk();

    $writes = $this->traceStorage()->writes((string) $this->lastTrace()['id']);
    $byTable = collect($writes)->keyBy('table');
    expect($byTable)->toHaveKeys(['users', 'notes'])
        ->and($byTable['notes']['connection'])->toBe('other')
        ->and($byTable['users']['connection'])->toBe('testing')
        ->and($byTable['notes']['operation'])->toBe('insert');
    $tables = collect($this->lastTrace()['tables_written'])->pluck('connection', 'table')->all();
    expect($tables)->toBe(['users' => 'testing', 'notes' => 'other']);
});
