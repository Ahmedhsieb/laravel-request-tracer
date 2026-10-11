<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RequestTracer\Recording\TokenService;
use RequestTracer\Tests\Fixtures\Jobs\AlwaysFails;
use RequestTracer\Tests\Fixtures\Jobs\RecalculateStats;
use RequestTracer\Tests\Fixtures\Models\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    putenv('REQUEST_TRACER');
    if (method_exists(app(Kernel::class), 'rerouteSymfonyCommandEvents')) {
        app(Kernel::class)->rerouteSymfonyCommandEvents();      // the framework only does this outside unit tests
    }
    Artisan::command('demo:count {--label=}', function () {
        User::query()->count();
        $this->line('counted');

        return 0;
    });
    Artisan::command('demo:fail', fn () => 3);
});

afterEach(fn () => putenv('REQUEST_TRACER'));

/** Runs a command the way `php artisan` does: through Kernel::handle(), which is what raises the CommandStarting / CommandFinished events. */
function runCommand(string $name, array $arguments = []): int
{
    return app(Kernel::class)->handle(new ArrayInput(['command' => $name] + $arguments), new BufferedOutput);
}

function storedTraces(object $test): int
{
    return $test->tracerTable('traces')->count();
}

it('records an artisan command as its own trace with its input, exit code and queries', function () {
    expect(runCommand('demo:count', ['--label' => 'nightly']))->toBe(0);

    $trace = $this->lastTrace();
    expect($trace)->toMatchArray(['kind' => 'command', 'path' => 'demo:count', 'status' => 0, 'mode' => 'command', 'method' => null])
        ->and($trace['queries_count'])->toBe(1);
    $root = $this->traceSpans()[0];
    expect($root['type'])->toBe('command')->and($root['meta']['summary'])->toBe('Exit code 0')
        ->and($root['input']['i'][0][0])->toBe('command');
});

it('stores the exit code of a command that fails', function () {
    expect(runCommand('demo:fail'))->toBe(3);

    expect($this->lastTrace())->toMatchArray(['kind' => 'command', 'status' => 3]);
});

it('never records excluded commands', function () {
    config(['request-tracer.exclude.commands' => ['demo:*']]);

    expect(runCommand('demo:count'))->toBe(0);

    expect(storedTraces($this))->toBe(0);
});

it('records a command in triggered mode only when the process was started with REQUEST_TRACER=1', function () {
    config(['request-tracer.mode' => 'triggered']);

    expect(runCommand('demo:count'))->toBe(0);
    expect(storedTraces($this))->toBe(0);

    putenv('REQUEST_TRACER=1');
    expect(runCommand('demo:count'))->toBe(0);
    expect(storedTraces($this))->toBe(1);
});

it('records nothing for commands when their collector is off', function () {
    config(['request-tracer.collectors.commands' => false]);

    expect(runCommand('demo:count'))->toBe(0);

    expect(storedTraces($this))->toBe(0);
});

it('records a job processed by a worker, linked to the request that dispatched it', function () {
    useDatabaseQueue();
    config(['request-tracer.mode' => 'triggered']);
    Route::get('/dispatch', function () {
        RecalculateStats::dispatch();

        return 'queued';
    });

    $token = app(TokenService::class)->issue()['token'];
    $this->withHeader('X-Request-Tracer', $token)->get('/dispatch')->assertOk();
    $request = $this->lastTrace();
    expect($request['kind'])->toBe('request');

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertExitCode(0);

    $job = $this->lastTrace();
    expect($job)->toMatchArray(['kind' => 'job', 'mode' => 'job', 'parent_trace_id' => $request['id']])
        ->and($job['path'])->toBe(RecalculateStats::class)
        ->and($job['queries_count'])->toBeGreaterThanOrEqual(1);
    $root = $this->traceSpans()[0];
    expect($root['type'])->toBe('job')->and($root['label'])->toBe('RecalculateStats')->and($root['meta']['summary'])->toBe('Processed');

    // the dashboard finds the job from the request
    $children = $this->traceStorage()->traces(['parent_trace_id' => $request['id']], null, 10)['data'];
    expect($children)->toHaveCount(1)->and($children[0]['id'])->toBe($job['id']);
});

it('does not record jobs in triggered mode when the request that dispatched them was not recorded', function () {
    useDatabaseQueue();
    config(['request-tracer.mode' => 'triggered']);
    Route::get('/dispatch', function () {
        RecalculateStats::dispatch();

        return 'queued';
    });

    $this->get('/dispatch')->assertOk();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertExitCode(0);

    expect(storedTraces($this))->toBe(0);
});

it('stores a failing job with the exception as an uncaught problem', function () {
    useDatabaseQueue();
    AlwaysFails::dispatch();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);

    $job = $this->lastTrace();
    expect($job)->toMatchArray(['kind' => 'job', 'problems_count' => 1]);
    expect($this->traceStorage()->problems($job['id'])[0])->toMatchArray(['kind' => 'exception_uncaught']);
    $root = $this->traceSpans()[0];
    expect($root['flag'])->toBe('failed')->and($root['meta']['summary'])->toContain('RuntimeException');
});

it('keeps a job that runs inside a request (sync queue) as part of that request', function () {
    Route::get('/sync', function () {
        RecalculateStats::dispatchSync();
        User::query()->count();                                 // must still be recorded after the job finished

        return 'done';
    });

    $this->get('/sync')->assertOk();

    expect(storedTraces($this))->toBe(1);
    $trace = $this->lastTrace();
    expect($trace['kind'])->toBe('request')->and($trace['queries_count'])->toBe(2);
});

it('records a scheduled closure as a schedule trace', function () {
    app(Schedule::class)->call(fn () => User::query()->count())->name('count users')->everyMinute();

    expect(runCommand('schedule:run'))->toBe(0);

    expect(storedTraces($this))->toBe(1);
    expect($this->lastTrace())->toMatchArray(['kind' => 'schedule', 'path' => 'count users', 'status' => 0, 'mode' => 'schedule', 'queries_count' => 1]);
});

it('stores one trace per job even when a worker runs many jobs, with no state leaking between them', function () {
    useDatabaseQueue();
    RecalculateStats::dispatch();
    RecalculateStats::dispatch();
    RecalculateStats::dispatch();

    $this->artisan('queue:work', ['--stop-when-empty' => true])->assertExitCode(0);

    expect(storedTraces($this))->toBe(3);
    $rows = $this->traceStorage()->traces([], null, 10)['data'];
    expect(array_unique(array_column($rows, 'kind')))->toBe(['job'])
        ->and(count(array_unique(array_column($rows, 'queries_count'))))->toBe(1)           // the 2nd and 3rd job did not inherit the 1st one's queries
        ->and($rows[0]['queries_count'])->toBeGreaterThanOrEqual(1);
});
