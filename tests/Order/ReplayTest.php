<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\ReplayRequest;
use RequestTracer\Tests\Fixtures\Models\Order;
use RequestTracer\Tests\Fixtures\Models\Product;
use RequestTracer\Tests\Fixtures\Models\User;

beforeEach(function () {
    config(['request-tracer.mode' => 'triggered']);     // nothing records on its own: a replay asks for it
    $this->replayer = new Replayer;
});

/** @return array{0: ReplayRequest, 1: User} */
function orderReplay(object $test, array $extra = []): array
{
    $user = $test->makeUser();
    $products = $test->seedCatalog();

    return [new ReplayRequest('POST', '/api/orders', ['Content-Type' => 'application/json'], json_encode($test->orderPayload($products, $extra)), $user->id), $user];
}

it('runs a request that already happened again, traced, and takes every database change back', function () {
    [$replay] = orderReplay($this);
    $stockBefore = Product::query()->sum('stock');

    $result = $this->replayer->run($replay);

    expect($result)->toMatchArray(['status' => 201, 'error' => null])->and($result['trace_id'])->toBeString();
    // nothing was left behind ...
    expect(Order::query()->count())->toBe(0)
        ->and(Product::query()->sum('stock'))->toEqual($stockBefore);
    // ... yet the trace shows what the request did, the tables it wrote included
    $trace = $this->traceStorage()->trace($result['trace_id']);
    expect($trace)->toMatchArray(['kind' => 'request', 'path' => '/api/orders', 'status' => 201, 'mode' => 'replay'])
        ->and(array_column($this->traceStorage()->writes($result['trace_id']), 'table'))->toContain('orders', 'order_items', 'products')
        ->and($trace['spans_count'])->toBeGreaterThan(10);
});

it('acts as the user it is told to, without logging in', function () {
    [$replay, $user] = orderReplay($this);

    $withUser = $this->replayer->run($replay);
    $withoutUser = $this->replayer->run($replay->withUser(null));

    expect($withUser['status'])->toBe(201)
        ->and($withoutUser['status'])->toBe(401)                      // the route needs `auth:web`
        ->and($this->traceStorage()->trace($withoutUser['trace_id']))->toMatchArray(['status' => 401, 'mode' => 'replay']);
});

it('says so when the user does not exist', function () {
    [$replay] = orderReplay($this);

    $result = $this->replayer->run($replay->withUser(99999));

    expect($result['trace_id'])->toBeNull()->and($result['error'])->toContain('no user with id 99999');
});

it('replays an excluded path and a path in triggered mode: asking for it is the trigger', function () {
    Route::get('/health-probe', fn () => response()->json(['ok' => true]));
    config(['request-tracer.exclude.paths' => ['health-probe']]);

    $result = $this->replayer->run(new ReplayRequest('GET', '/health-probe'));

    expect($result)->toMatchArray(['status' => 200, 'error' => null])
        ->and($this->traceStorage()->trace($result['trace_id'])['mode'])->toBe('replay');
});

it('keeps e-mail, queued jobs and broadcasts from going anywhere', function () {
    [$replay] = orderReplay($this);

    $this->replayer->run($replay);

    expect(config('mail.default'))->toBe('array')
        ->and(config('queue.default'))->toBe('request-tracer-discard')
        ->and(config('broadcasting.default'))->toBe('null');
});

it('does not replay in the production environment unless it is allowed explicitly', function () {
    config(['request-tracer.replay.enabled' => null]);
    app()->detectEnvironment(fn (): string => 'production');

    expect(Replayer::allowed())->toBeFalse()
        ->and($this->replayer->run(new ReplayRequest('GET', '/anything'))['error'])->toContain('switched off');

    config(['request-tracer.replay.enabled' => true]);
    expect(Replayer::allowed())->toBeTrue();

    config(['request-tracer.replay.enabled' => false]);
    app()->detectEnvironment(fn (): string => 'local');
    expect(Replayer::allowed())->toBeFalse();
});

it('is not left switched on after a replay, so later requests are decided as usual', function () {
    Route::get('/probe', fn () => 'pong');

    $this->replayer->run(new ReplayRequest('GET', '/probe'));
    $before = $this->tracerTable('traces')->count();
    $this->get('/probe')->assertOk();

    expect(Replayer::active())->toBeFalse()->and($this->tracerTable('traces')->count())->toBe($before);
});

it('replays through the command, in this process when the process was told to trace', function () {
    putenv('REQUEST_TRACER=1');
    Route::get('/probe', fn () => 'pong');
    try {
        $this->artisan('request-tracer:replay', ['request' => 'GET /probe', '--json' => true])
            ->expectsOutputToContain(Replayer::RESULT_PREFIX)
            ->assertExitCode(0);
    } finally {
        putenv('REQUEST_TRACER');
    }

    expect($this->tracerTable('traces')->where('path', '/probe')->where('mode', 'replay')->count())->toBe(1);
});

it('takes a pasted cURL command, headers and a body for the command', function () {
    putenv('REQUEST_TRACER=1');
    Route::post('/echo', fn (Request $r) => response()->json(['x' => $r->header('X-Marker'), 'q' => $r->input('q')]));
    try {
        $this->artisan('request-tracer:replay', ['--curl' => "curl 'http://localhost/echo' -H 'X-Marker: abc' -H 'content-type: application/json' --data-raw '{\"q\":5}'", '--json' => true])
            ->assertExitCode(0);
    } finally {
        putenv('REQUEST_TRACER');
    }

    $trace = $this->lastTrace();
    expect($trace)->toMatchArray(['method' => 'POST', 'path' => '/echo', 'status' => 200, 'mode' => 'replay']);
    $root = $this->traceSpans()[0];
    expect(json_encode($root['output']))->toContain('abc');
});
