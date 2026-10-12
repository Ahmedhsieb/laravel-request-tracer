<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\ReplayRequest;
use RequestTracer\Replay\UndoLog;
use RequestTracer\Tests\Fixtures\Models\Order;
use RequestTracer\Tests\Fixtures\Models\Product;

beforeEach(function () {
    config(['request-tracer.mode' => 'triggered']);
    $this->replayer = new Replayer;
    @mkdir(config('request-tracer.replay.undo_path'), 0775, true);
});

afterEach(function () {
    foreach (glob(config('request-tracer.replay.undo_path').'/*.undo') ?: [] as $file) {
        @unlink($file);
    }
});

/** The order flow as a replay of the given mode. */
function modeReplay(object $test, string $mode): ReplayRequest
{
    $user = $test->makeUser();
    $products = $test->seedCatalog();

    return (new ReplayRequest('POST', '/api/orders', ['Content-Type' => 'application/json'], json_encode($test->orderPayload($products, [])), $user->id))->withMode($mode);
}

it('changes nothing in simulation, which is the default mode', function () {
    $replay = modeReplay($this, ReplayRequest::SIMULATE);
    $stock = Product::query()->sum('stock');

    $result = $this->replayer->run($replay);

    expect($replay->mode)->toBe('simulate')
        ->and($result)->toMatchArray(['status' => 201, 'error' => null, 'mode' => 'simulate', 'undo' => null])
        ->and(Order::query()->count())->toBe(0)
        ->and(Product::query()->sum('stock'))->toEqual($stock);
});

it('answers the calls of the HTTP client locally and keeps the cache in memory in simulation', function () {
    config(['cache.default' => 'array']);
    Route::get('/outside', function () {
        Cache::put('replayed', 'yes');

        return response()->json(['called' => Http::get('https://example.invalid/pay')->json('simulated')]);
    });

    $result = $this->replayer->run(new ReplayRequest('GET', '/outside'));

    expect($result['status'])->toBe(200)
        ->and(config('cache.default'))->toBe('request-tracer-memory')
        ->and(Cache::store('array')->get('replayed'))->toBeNull();
    $trace = $this->traceStorage()->trace($result['trace_id']);
    expect($trace['status'])->toBe(200);
});

it('keeps the changes of a real run, and records what it can undo', function () {
    $replay = modeReplay($this, ReplayRequest::REAL);
    $stock = Product::query()->sum('stock');

    $result = $this->replayer->run($replay);

    expect($result)->toMatchArray(['status' => 201, 'error' => null, 'mode' => 'real'])
        ->and(Order::query()->count())->toBe(1)
        ->and(Product::query()->sum('stock'))->not->toEqual($stock)
        ->and($result['undo'])->toBeArray()
        ->and($result['undo']['available'])->toBeTrue()
        ->and($result['undo']['tables'])->toHaveKey('orders')
        ->and(config('mail.default'))->not->toBe('array');
});

it('takes a real run back, exactly, and only once', function () {
    $replay = modeReplay($this, ReplayRequest::REAL);
    $before = [Order::query()->count(), Product::query()->orderBy('id')->pluck('stock', 'id')->all(), DB::table('order_items')->count()];

    $result = $this->replayer->run($replay);
    expect(Order::query()->count())->toBe(1);

    $undo = UndoLog::apply($result['trace_id']);

    expect($undo)->toMatchArray(['applied' => true, 'error' => null, 'conflicts' => []])
        ->and($undo['undone'])->toBeGreaterThan(0)
        ->and([Order::query()->count(), Product::query()->orderBy('id')->pluck('stock', 'id')->all(), DB::table('order_items')->count()])->toEqual($before)
        ->and(UndoLog::summary($result['trace_id']))->toBeNull()
        ->and(UndoLog::apply($result['trace_id'])['error'])->toContain('nothing to undo');
});

it('refuses to undo a row that changed after the replay, unless it is forced', function () {
    $result = $this->replayer->run(modeReplay($this, ReplayRequest::REAL));
    $order = Order::query()->firstOrFail();
    $order->forceFill(['status' => 'shipped-by-someone-else'])->save();
    $changed = Order::query()->firstOrFail()->getAttributes();

    $refused = UndoLog::apply($result['trace_id']);

    expect($refused['applied'])->toBeFalse()
        ->and($refused['conflicts'])->not->toBeEmpty()
        ->and(Order::query()->count())->toBe(1)                   // all or nothing: not even the rows that were fine
        ->and(Order::query()->firstOrFail()->getAttributes())->toEqual($changed);

    $forced = UndoLog::apply($result['trace_id'], true);

    expect($forced['applied'])->toBeTrue()->and(Order::query()->count())->toBe(0);
});

it('says what it cannot undo: writes that bypass Eloquent', function () {
    Route::post('/bulk', function () {
        DB::table('products')->update(['stock' => 1]);

        return response()->json(['ok' => true]);
    });
    $this->seedCatalog();

    $result = $this->replayer->run((new ReplayRequest('POST', '/bulk'))->withMode(ReplayRequest::REAL));

    expect($result['status'])->toBe(200)
        ->and($result['undo']['available'])->toBeFalse()
        ->and($result['undo']['uncovered'])->toHaveKey('products')
        ->and(Product::query()->max('stock'))->toBe(1);          // it ran for real, and the log admits it cannot take that back
});

it('never keeps the values of a real run in clear text', function () {
    $result = $this->replayer->run(modeReplay($this, ReplayRequest::REAL));

    $raw = (string) file_get_contents(config('request-tracer.replay.undo_path').'/'.$result['trace_id'].'.undo');

    expect($raw)->not->toContain('orders')->and($raw)->not->toContain('{');
});

it('runs for real only where that is allowed', function () {
    config(['request-tracer.replay.real' => false]);

    expect(Replayer::realAllowed())->toBeFalse()
        ->and($this->replayer->run(modeReplay($this, ReplayRequest::REAL))['error'])->toContain('for real is switched off')
        ->and(Order::query()->count())->toBe(0);

    config(['request-tracer.replay.real' => null]);
    app()->detectEnvironment(fn (): string => 'production');
    config(['request-tracer.replay.enabled' => true]);
    expect(Replayer::realAllowed())->toBeFalse();               // production needs `real => true` as well

    config(['request-tracer.replay.real' => true]);
    expect(Replayer::realAllowed())->toBeTrue();

    app()->detectEnvironment(fn (): string => 'local');
});

it('asks for a confirmation before the dashboard runs a request for real', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);

    $this->postJson('/request-tracer/api/v1/replay', ['method' => 'GET', 'url' => '/x', 'mode' => 'real'])
        ->assertStatus(422);
    $this->postJson('/request-tracer/api/v1/replay', ['method' => 'GET', 'url' => '/x', 'mode' => 'nonsense'])
        ->assertStatus(422);
});

it('reports what can be undone, and undoes it, through the API', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
    $result = $this->replayer->run(modeReplay($this, ReplayRequest::REAL));

    $this->getJson('/request-tracer/api/v1/traces/'.$result['trace_id'].'/undo')
        ->assertOk()->assertJsonPath('data.available', true)->assertJsonStructure(['data' => ['entries', 'tables', 'uncovered', 'expires_at']]);

    $this->postJson('/request-tracer/api/v1/traces/'.$result['trace_id'].'/undo')
        ->assertOk()->assertJsonPath('data.applied', true);

    expect(Order::query()->count())->toBe(0);
});

it('undoes through the command and says what is left', function () {
    $result = $this->replayer->run(modeReplay($this, ReplayRequest::REAL));

    $this->artisan('request-tracer:undo', ['trace' => $result['trace_id'], '--yes' => true])->assertExitCode(0);
    expect(Order::query()->count())->toBe(0);

    $this->artisan('request-tracer:undo', ['trace' => $result['trace_id'], '--yes' => true])->assertExitCode(1);
});

it('forgets what can no longer be undone', function () {
    $result = $this->replayer->run(modeReplay($this, ReplayRequest::REAL));
    $file = config('request-tracer.replay.undo_path').'/'.$result['trace_id'].'.undo';
    touch($file, time() - 3 * 86400);

    expect(UndoLog::prune())->toBe(1)->and(is_file($file))->toBeFalse();
});

it('runs for real from the command only when it is told to, and prints how to take it back', function () {
    putenv('REQUEST_TRACER=1');
    $user = $this->makeUser();
    $products = $this->seedCatalog();
    $body = json_encode($this->orderPayload($products, []));
    try {
        $this->artisan('request-tracer:replay', ['request' => 'POST /api/orders', '--data' => $body, '--header' => ['Content-Type: application/json'], '--as' => (string) $user->id])
            ->assertExitCode(0);
        expect(Order::query()->count())->toBe(0);               // a simulation unless --real

        $this->artisan('request-tracer:replay', ['request' => 'POST /api/orders', '--data' => $body, '--header' => ['Content-Type: application/json'], '--as' => (string) $user->id, '--real' => true, '--force' => true])
            ->expectsOutputToContain('request-tracer:undo')
            ->assertExitCode(0);
    } finally {
        putenv('REQUEST_TRACER');
    }

    expect(Order::query()->count())->toBe(1);
});

it('refuses to run for real from the command where that is switched off', function () {
    putenv('REQUEST_TRACER=1');
    config(['request-tracer.replay.real' => false]);
    Route::post('/never', fn () => response()->json(['ran' => true]));
    try {
        $this->artisan('request-tracer:replay', ['request' => 'POST /never', '--real' => true, '--force' => true])
            ->assertExitCode(1);
    } finally {
        putenv('REQUEST_TRACER');
    }
});

it('carries the mode in the payload the child process reads', function () {
    $replay = (new ReplayRequest('POST', '/x', [], null, null))->withMode(ReplayRequest::REAL);

    expect(ReplayRequest::fromArray($replay->toArray())->mode)->toBe('real')
        ->and(ReplayRequest::fromArray(['url' => '/x', 'mode' => 'anything-else'])->mode)->toBe('simulate')
        ->and($replay->withUser(7)->mode)->toBe('real');
});
