<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/hello', fn () => response()->json(['ok' => true, 'password' => 'hunter2']));
    Route::post('/echo', fn (Request $r) => response()->json(['got' => $r->input('name')], 201));
});

it('records a request and stores it in terminate()', function () {
    $this->getJson('/hello')->assertOk();

    $trace = $this->tracerTable('traces')->first();

    expect($trace)->not->toBeNull()
        ->and($trace->method)->toBe('GET')
        ->and($trace->path)->toBe('/hello')
        ->and((int) $trace->status)->toBe(200)
        ->and($trace->kind)->toBe('request')
        ->and((int) $trace->spans_count)->toBeGreaterThanOrEqual(1)
        ->and(strlen($trace->id))->toBe(26);
});

it('stores the root span with request input and response output', function () {
    $this->postJson('/echo', ['name' => 'Ann', 'password' => 'hunter2'])->assertCreated();

    $storage = app(TraceStorage::class);
    $trace = $storage->traces()['data'][0];
    $spans = $storage->spans($trace['id']);
    $root = $spans[0];

    expect($root['type'])->toBe('request')
        ->and($root['label'])->toBe('POST /echo')
        ->and($root['parent_id'])->toBeNull()
        ->and($root['has_input'])->toBeTrue()
        ->and($root['has_output'])->toBeTrue();

    $payload = $storage->payload($trace['id'], $root['id']);
    expect($payload['input'])->toContain('Ann')->and($payload['output'])->toContain('201');
});

it('never lets a masked value reach any stored column', function () {
    $this->postJson('/echo', ['name' => 'Ann', 'password' => 'hunter2', 'api_key' => 'sk_live_abc'])->assertCreated();
    $this->getJson('/hello');

    foreach (['traces', 'spans', 'lines', 'writes', 'problems'] as $table) {
        $dump = json_encode($this->tracerTable($table)->get()->all());
        expect($dump)->not->toContain('hunter2')->not->toContain('sk_live_abc');
    }
});

it('writes nothing while the request runs and persists after the response (terminate)', function () {
    $during = null;
    $recorded = null;
    Route::get('/inside', function () use (&$during, &$recorded) {
        $during = DB::connection('testing')->table('tracer_traces')->count();
        $recorded = app(Tracer::class)->session()?->recorder->count();

        return 'ok';
    });

    $this->get('/inside');

    expect($recorded)->toBeGreaterThanOrEqual(1)          // recording was live during the request
        ->and($during)->toBe(0)                            // ...but nothing had been written yet
        ->and($this->tracerTable('traces')->count())->toBe(1);
});

it('does not record excluded paths or when disabled', function () {
    Route::get('/login', fn () => 'login page');
    Route::get('/request-tracer/ping', fn () => 'x');

    $this->get('/login');
    $this->get('/request-tracer/ping');
    expect($this->tracerTable('traces')->count())->toBe(0);

    config(['request-tracer.mode' => 'off']);
    $this->get('/hello');
    expect($this->tracerTable('traces')->count())->toBe(0);
});

it('resets all per-request state after every request', function () {
    $this->get('/hello');
    $tracer = app(Tracer::class);

    expect($tracer->on)->toBeFalse()->and($tracer->session())->toBeNull();
});
