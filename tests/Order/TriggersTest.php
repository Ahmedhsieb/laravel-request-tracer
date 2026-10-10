<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use RequestTracer\Recording\TokenService;
use RequestTracer\Recording\WatchList;
use RequestTracer\Support\Failure;
use RequestTracer\Tests\Fixtures\Models\User;

beforeEach(function () {
    config(['request-tracer.mode' => 'triggered']);
    Route::get('/probe', fn () => 'pong');
    Route::get('/api/orders/ping', fn () => 'pong');
    // authenticates *during* the request, the way a session or token guard does
    Route::get('/as/{id}', function (string $id) {
        Auth::guard('web')->setUser(User::findOrFail($id));

        return 'hello';
    });
});

function token(array $args = []): string
{
    return app(TokenService::class)->issue(...$args)['token'];
}

function traceCount($test): int
{
    return $test->tracerTable('traces')->count();
}

it('records nothing in triggered mode without a trigger', function () {
    $this->get('/probe')->assertOk();

    expect(traceCount($this))->toBe(0);
});

it('records a request that carries a valid signed header, even when nobody is signed in', function () {
    $this->withHeader('X-Request-Tracer', token())->get('/probe')->assertOk();

    expect(traceCount($this))->toBe(1)->and($this->lastTrace()['mode'])->toBe('token');
});

it('ignores invalid tokens silently: the response is unaffected, nothing is recorded, a debug line is logged', function () {
    $logged = [];
    Failure::useSink(function (string $message) use (&$logged) {
        $logged[] = $message;
    });

    $valid = token();
    $tampered = substr($valid, 0, -4).'AAAA';
    foreach (['not-a-token', $tampered, 'rt1.e30.e30', ''] as $bad) {
        $this->withHeader('X-Request-Tracer', $bad)->get('/probe')->assertOk()->assertSee('pong');
    }
    $this->withHeader('X-Request-Tracer', app(TokenService::class)->issue(60, now: time() - 7200)['token'])->get('/probe')->assertOk();

    expect(traceCount($this))->toBe(0)
        ->and(implode("\n", $logged))->toContain('recording token ignored: bad signature')->toContain('recording token ignored: expired');
});

it('honours the route a token was issued for', function () {
    $scoped = token(['route' => 'api/orders*']);

    $this->withHeader('X-Request-Tracer', $scoped)->get('/probe')->assertOk();
    expect(traceCount($this))->toBe(0);

    $this->withHeader('X-Request-Tracer', $scoped)->get('/api/orders/ping')->assertOk();
    expect(traceCount($this))->toBe(1);
});

it('never records excluded paths, valid token or not', function () {
    Route::get('/login', fn () => 'login');
    Route::get('/request-tracer/anything', fn () => 'x');
    $t = token();

    $this->withHeader('X-Request-Tracer', $t)->get('/login')->assertOk();
    $this->withHeader('X-Request-Tracer', $t)->get('/request-tracer/anything');

    expect(traceCount($this))->toBe(0);
});

it('a token bound to a user records only when that user signs in', function () {
    $ann = $this->makeUser();
    $bob = $this->makeUser();
    $bound = token(['userId' => $ann->id]);

    $this->withHeader('X-Request-Tracer', $bound)->get('/probe')->assertOk();                        // nobody signed in
    $this->withHeader('X-Request-Tracer', $bound)->get('/as/'.$bob->id)->assertOk();                 // someone else
    expect(traceCount($this))->toBe(0);

    $this->withHeader('X-Request-Tracer', $bound)->get('/as/'.$ann->id)->assertOk();
    expect(traceCount($this))->toBe(1)->and($this->lastTrace()['user_id'])->toBe((string) $ann->id);
});

it('records only watched users, and only until the watch expires', function () {
    $ann = $this->makeUser();
    $bob = $this->makeUser();
    app(WatchList::class)->add($ann->id, 30);

    $this->get('/as/'.$bob->id)->assertOk();
    $this->get('/probe')->assertOk();                                                                  // anonymous: never
    expect(traceCount($this))->toBe(0);

    $this->get('/as/'.$ann->id)->assertOk();
    expect(traceCount($this))->toBe(1)->and($this->lastTrace()['mode'])->toBe('watch');

    app(WatchList::class)->remove($ann->id);
    $this->get('/as/'.$ann->id)->assertOk();
    expect(traceCount($this))->toBe(1);
});

it('expires watch list entries', function () {
    $watch = app(WatchList::class);
    $watch->add(7, 5);
    expect($watch->contains(7))->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(6));
    expect($watch->contains(7))->toBeFalse()->and($watch->any())->toBeFalse();
    Carbon::setTestNow();
});

it('samples requests but keeps them only when a user is authenticated', function () {
    config(['request-tracer.triggers.sampling' => ['enabled' => true, 'rate' => 1.0, 'require_auth' => true]]);
    $ann = $this->makeUser();

    $this->get('/probe')->assertOk();
    expect(traceCount($this))->toBe(0);                                                               // sampled, but nobody signed in

    $this->get('/as/'.$ann->id)->assertOk();
    expect(traceCount($this))->toBe(1)->and($this->lastTrace()['mode'])->toBe('sample');
});

it('can sample anonymous requests when the application chooses to', function () {
    config(['request-tracer.triggers.sampling' => ['enabled' => true, 'rate' => 1.0, 'require_auth' => false]]);

    $this->get('/probe')->assertOk();

    expect(traceCount($this))->toBe(1);
});

it('does not sample at rate zero', function () {
    config(['request-tracer.triggers.sampling' => ['enabled' => true, 'rate' => 0.0, 'require_auth' => false]]);
    $this->get('/probe');

    expect(traceCount($this))->toBe(0);
});

it('writes nothing for a tentative recording that is never confirmed', function () {
    config(['request-tracer.triggers.sampling' => ['enabled' => true, 'rate' => 1.0, 'require_auth' => true]]);
    $queries = [];
    DB::listen(function ($q) use (&$queries) {
        $queries[] = $q->sql;
    });

    $this->get('/probe')->assertOk();

    expect(array_filter($queries, fn ($sql) => str_contains($sql, 'tracer_')))->toBe([]);
});

it('does not run the watch list check at all when it is switched off', function () {
    config(['request-tracer.triggers.watch_list.enabled' => false]);
    $ann = $this->makeUser();
    app(WatchList::class)->add($ann->id, 30);

    $this->get('/as/'.$ann->id)->assertOk();

    expect(traceCount($this))->toBe(0);
});

it('always records in local-style always mode regardless of triggers', function () {
    config(['request-tracer.mode' => 'always']);
    $this->get('/probe');

    expect(traceCount($this))->toBe(1)->and($this->lastTrace()['mode'])->toBe('always');
});
