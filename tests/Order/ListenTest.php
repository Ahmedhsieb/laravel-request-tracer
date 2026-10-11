<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RequestTracer\Console\ListenCommand;
use RequestTracer\Recording\Listening;
use RequestTracer\Storage\TableInstaller;
use RequestTracer\Tests\Fixtures\Jobs\RecalculateStats;
use RequestTracer\Tests\Fixtures\Models\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** The listen command with a hook in its pause, so a test can do something "while it listens". */
class TickingListenCommand extends ListenCommand
{
    public static ?Closure $onTick = null;

    protected function sleep(): void
    {
        if (self::$onTick !== null) {
            $tick = self::$onTick;
            self::$onTick = null;
            $tick();
        }
        usleep(10_000);
    }
}

beforeEach(function () {
    $this->heartbeat = sys_get_temp_dir().'/rt-heartbeat-'.uniqid();
    Listening::useFile($this->heartbeat);
    config(['request-tracer.mode' => 'triggered']);
    Route::get('/probe', fn () => 'pong');
    Route::get('/health-check', fn () => 'ok');
    if (method_exists(app(Kernel::class), 'rerouteSymfonyCommandEvents')) {
        app(Kernel::class)->rerouteSymfonyCommandEvents();
    }
    Artisan::command('demo:count', function () {
        User::query()->count();

        return 0;
    });
});

afterEach(function () {
    @unlink($this->heartbeat);
    Listening::useFile(null);
    TickingListenCommand::$onTick = null;
});

function traces(object $test): int
{
    return $test->tracerTable('traces')->count();
}

it('records nothing by default, even though the environment is not production', function () {
    $this->get('/probe')->assertOk();

    expect(traces($this))->toBe(0);
});

it('records every request while somebody is listening, and says why', function () {
    Listening::beat();

    $this->get('/probe')->assertOk();
    $this->get('/probe')->assertOk();

    expect(traces($this))->toBe(2)->and($this->lastTrace()['mode'])->toBe('listen');
});

it('stops recording a few seconds after the listener stopped', function () {
    Listening::beat();
    touch($this->heartbeat, time() - 30);                    // the listen command was killed 30 seconds ago

    $this->get('/probe')->assertOk();

    expect(Listening::active())->toBeFalse()->and(traces($this))->toBe(0);
});

it('still leaves out the excluded paths while listening', function () {
    Listening::beat();

    $this->get('/health-check')->assertOk();                  // `health*` is excluded by default

    expect(traces($this))->toBe(0);
});

it('records artisan commands and queued jobs of the same project while listening', function () {
    $run = fn (string $name) => app(Kernel::class)->handle(new ArrayInput(['command' => $name]), new BufferedOutput);

    $run('demo:count');
    expect(traces($this))->toBe(0);

    Listening::beat();
    $run('demo:count');
    expect(traces($this))->toBe(1)->and($this->lastTrace())->toMatchArray(['kind' => 'command', 'mode' => 'command']);
});

it('records a job nobody stamped while listening, but not without', function () {
    config(['queue.default' => 'database', 'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90, 'after_commit' => false]]);
    useDatabaseQueue();
    RecalculateStats::dispatch();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    expect(traces($this))->toBe(0);

    RecalculateStats::dispatch();
    Listening::beat();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    expect(traces($this))->toBe(1)->and($this->lastTrace()['kind'])->toBe('job');
});

it('keeps the heartbeat going while it runs, prints what it traced, and cleans up when it stops', function () {
    app(Kernel::class)->registerCommand($this->app->make(TickingListenCommand::class));
    TickingListenCommand::$onTick = function () {
        expect(Listening::active())->toBeTrue();            // the command beats before it pauses
        $this->get('/probe');
    };

    $this->artisan('request-tracer:listen', ['--seconds' => 0.4])
        ->expectsOutputToContain('Listening')
        ->expectsOutputToContain('GET /probe')
        ->expectsOutputToContain('Stopped. 1 trace(s) were stored')
        ->assertExitCode(0);

    expect(file_exists($this->heartbeat))->toBeFalse()->and(Listening::active())->toBeFalse();
});

it('refuses to listen when recording is switched off', function () {
    config(['request-tracer.mode' => 'off']);

    $this->artisan('request-tracer:listen', ['--seconds' => 0.1])->assertExitCode(1);

    expect(file_exists($this->heartbeat))->toBeFalse();
});

it('creates its own tables the first time a trace is stored', function () {
    foreach (['problems', 'writes', 'lines', 'spans', 'traces'] as $table) {
        Schema::dropIfExists('tracer_'.$table);
    }
    TableInstaller::reset();
    Listening::beat();

    $this->get('/probe')->assertOk();

    expect(Schema::hasTable('tracer_traces'))->toBeTrue()->and(traces($this))->toBe(1);
});

it('can run its migration twice without harm (a published copy plus the one the package loads)', function () {
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000000_create_request_tracer_tables.php';

    $migration->up();
    $migration->up();

    expect(Schema::hasTable('tracer_traces'))->toBeTrue();
});

it('opens the dashboard on a fresh install, before a single trace exists, instead of failing on missing tables', function () {
    foreach (['problems', 'writes', 'lines', 'spans', 'traces'] as $table) {
        Schema::dropIfExists('tracer_'.$table);
    }
    TableInstaller::reset();
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);

    $this->getJson('/request-tracer/api/v1/traces')->assertOk()->assertJsonPath('data', []);

    expect(Schema::hasTable('tracer_traces'))->toBeTrue();
});
