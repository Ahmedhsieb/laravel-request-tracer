<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use RequestTracer\Recording\Decision;
use RequestTracer\Recording\RecordingDecider;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\Support\Failure;
use RequestTracer\Tests\Fixtures\Models\Order;
use RequestTracer\Tests\Fixtures\Models\Product;
use RequestTracer\Tests\Fixtures\Models\User;

/** Run the order endpoint as an authenticated user. */
function placeOrder($test, array $payload, ?User $user = null)
{
    return $test->actingAs($user ?? $test->makeUser())->postJson('/api/orders', $payload);
}

it('records route, controller, validation, queries, http call, event and log for POST /api/orders', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products))->assertCreated();

    $trace = $this->lastTrace();
    $spans = $this->traceSpans();

    expect($trace['route_name'])->toBe('orders.store')
        ->and($trace['status'])->toBe(201)
        ->and($trace['queries_count'])->toBeGreaterThan(5);

    $controller = $this->spansOf($spans, 'controller')[0];
    expect($controller['label'])->toBe('OrderController@store')
        ->and($controller['file'])->toContain('OrderController.php');

    expect($this->spansOf($spans, 'validation'))->not->toBeEmpty()
        ->and($this->spansOf($spans, 'query', 'insert into "orders"'))->not->toBeEmpty()
        ->and($this->spansOf($spans, 'http', 'POST payments.test/v1/charges'))->toHaveCount(1)
        ->and($this->spansOf($spans, 'event', 'OrderPlaced'))->toHaveCount(1)
        ->and($this->spansOf($spans, 'log', 'Order confirmation sent'))->toHaveCount(1)
        ->and($this->spansOf($spans, 'model'))->not->toBeEmpty();

    // the spans form one tree under the request span
    $root = $spans[0];
    expect($root['type'])->toBe('request');
    $ids = array_column($spans, 'id');
    foreach (array_slice($spans, 1) as $span) {
        expect($ids)->toContain($span['parent_id']);
    }
});

it('puts queries under the controller and tags them with connection and calling file', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $spans = $this->traceSpans();
    $controller = $this->spansOf($spans, 'controller')[0];
    $insert = $this->spansOf($spans, 'query', 'insert into "orders"')[0];

    expect($insert['connection'])->toBe('testing')
        ->and($insert['file'])->toContain('OrderService.php')
        ->and($insert['line'])->toBeInt()
        ->and(str_replace(['"', '`'], '', $insert['input']['i'][0][1]))->toContain('insert into orders');
});

it('records every table written, with rows and before/after values', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $writes = $this->traceWrites();
    $byTable = [];
    foreach ($writes as $w) {
        $byTable[$w['table']][] = $w;
    }

    expect(array_keys($byTable))->toEqualCanonicalizing(['orders', 'order_items', 'products']);

    expect($byTable['orders'][0]['operation'])->toBe('insert')
        ->and($byTable['orders'][0]['after'])->toMatchArray(['status' => 'pending', 'total' => 85])
        ->and($byTable['orders'][0]['before'])->toBeNull();

    expect($byTable['order_items'])->toHaveCount(3)
        ->and($byTable['order_items'][0]['operation'])->toBe('insert');

    // the three stock updates: before/after for the changed column
    $stock = array_values(array_filter($byTable['products'], fn ($w) => $w['operation'] === 'update'));
    expect($stock)->toHaveCount(3);
    expect($stock[0]['before']['stock'])->toBe(100)->and($stock[0]['after']['stock'])->toBe(98)
        ->and($stock[2]['before']['stock'])->toBe(5)->and($stock[2]['after']['stock'])->toBe(4);

    // the status update that follows payment
    $orderUpdate = array_values(array_filter($byTable['orders'], fn ($w) => $w['operation'] === 'update'));
    expect($orderUpdate[0]['before'])->toMatchArray(['status' => 'pending'])->and($orderUpdate[0]['after'])->toMatchArray(['status' => 'paid']);

    expect($this->lastTrace()['tables_written'])->toBeArray()->not->toBeEmpty();
});

it('notes whether writes ran in a transaction that committed', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $writes = $this->traceWrites();
    $insert = array_values(array_filter($writes, fn ($w) => $w['table'] === 'orders' && $w['operation'] === 'insert'))[0];
    $statusUpdate = array_values(array_filter($writes, fn ($w) => $w['table'] === 'orders' && $w['operation'] === 'update'))[0];

    expect($insert['transaction'])->toBe('committed')      // DB::transaction(...) in OrderService
        ->and($statusUpdate['transaction'])->toBe('none'); // after the transaction
});

it('marks writes of a rolled back transaction and records the uncaught exception', function () {
    $products = $this->seedCatalog();
    $payload = $this->orderPayload($products, ['coupon' => null]);
    $payload['items'][2]['qty'] = 99;                       // more than the 5 in stock -> OutOfStockException

    placeOrder($this, $payload)->assertStatus(500);

    $writes = $this->traceWrites();
    $inserted = array_values(array_filter($writes, fn ($w) => $w['table'] === 'orders'));
    expect($inserted[0]['transaction'])->toBe('rolled_back');
    expect(array_column($this->traceProblems(), 'kind'))->toContain('exception_uncaught');

    $exception = $this->spansOf($this->traceSpans(), 'exception')[0];
    expect($exception['label'])->toContain('OutOfStockException')->and($exception['flag'])->toBe('failed');
});

it('records a caught exception that went through report()', function () {
    Route::get('/caught', function () {
        try {
            throw new RuntimeException('could not reach cache');
        } catch (RuntimeException $e) {
            report($e);
        }

        return response('still fine', 200);
    });

    $this->get('/caught')->assertOk();

    $problems = $this->traceProblems();
    $caught = array_values(array_filter($problems, fn ($p) => $p['kind'] === 'exception_caught'));

    expect($caught)->toHaveCount(1)
        ->and($caught[0]['message'])->toContain('could not reach cache')->toContain('the response was still 200')
        ->and($this->spansOf($this->traceSpans(), 'exception')[0]['flag'])->toBe('caught');
});

it('does not turn validation failures into problems but shows the failed validation span', function () {
    $this->seedCatalog();

    placeOrder($this, ['items' => []])->assertStatus(422);

    $spans = $this->traceSpans();
    $validation = $this->spansOf($spans, 'validation')[0];

    expect($validation['flag'])->toBe('failed')
        ->and(json_encode($validation['output']))->toContain('items')
        ->and(array_column($this->traceProblems(), 'kind'))->not->toContain('exception_uncaught');
});

it('detects the same UPDATE running in a loop', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $problems = $this->traceProblems();
    $repeated = array_values(array_filter($problems, fn ($p) => $p['kind'] === 'repeated_query' && str_contains($p['message'], 'UPDATE')));

    expect($repeated)->not->toBeEmpty()->and($repeated[0]['message'])->toContain('ran 3 times');
});

it('detects N+1 and identical repeated queries', function () {
    $products = $this->seedCatalog();
    Route::get('/n-plus-one', function () use ($products) {
        foreach (range(1, 6) as $i) {
            Product::query()->find($products[$i % 3]->id);         // 6 queries, 3 distinct bindings, each twice
        }
        foreach (range(1, 6) as $i) {
            DB::table('products')->where('sku', 'SKU-'.chr(64 + $i))->first();   // N+1: 6 distinct values
        }

        return 'ok';
    });

    $this->get('/n-plus-one')->assertOk();

    $kinds = array_column($this->traceProblems(), 'kind');
    expect($kinds)->toContain('n_plus_one')->toContain('repeated_query');
});

it('flags slow queries and collapses identical ones into one span', function () {
    config(['request-tracer.problems.slow_query_ms' => 0.0001, 'request-tracer.limits.collapse_after' => 3]);
    $this->seedCatalog();

    Route::get('/many', function () {
        foreach (range(1, 30) as $i) {
            DB::table('products')->where('id', 1)->first();
        }

        return 'ok';
    });
    $this->get('/many')->assertOk();

    $spans = $this->traceSpans();
    $selects = $this->spansOf($spans, 'query', 'select * from "products" where "id" = ?');

    expect($selects)->toHaveCount(4)                                    // 3 real + 1 aggregate
        ->and(end($selects)['count'])->toBe(27)
        ->and(end($selects)['flag'])->toBe('repeated')
        ->and($this->lastTrace()['queries_count'])->toBeGreaterThanOrEqual(30)
        ->and(array_column($this->traceProblems(), 'kind'))->toContain('slow_query');
});

it('masks secrets in outgoing HTTP calls, logs, query bindings and stored writes', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $stored = $this->everythingStored();

    foreach (['sk_live_super_secret_key', 'query_secret_123', '4111111111111111', 'tok_should_be_masked', 'tok_user_secret', 'secret-password'] as $secret) {
        expect($stored)->not->toContain($secret);
    }
    expect($stored)->toContain('payments.test');                                  // the call itself is still visible

    $http = $this->spansOf($this->traceSpans(), 'http')[0];
    expect(json_encode($http['input']))->toContain('"$":"mask"')->and($http['meta']['status'])->toBe(200);
});

it('lists the listeners of an event', function () {
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $event = $this->spansOf($this->traceSpans(), 'event', 'OrderPlaced')[0];

    expect($event['meta']['listeners'])->toContain('RequestTracer\Tests\Fixtures\Listeners\SendOrderConfirmation@handle');
});

it('records failed outgoing calls as problems', function () {
    Http::swap(new Factory(app('events')));
    Http::fake(['payments.test/*' => Http::response(['error' => 'down'], 503)]);
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products));

    $kinds = array_column($this->traceProblems(), 'kind');
    expect($kinds)->toContain('http_failed');
    expect($this->spansOf($this->traceSpans(), 'http')[0]['flag'])->toBe('failed');
});

it('records cache operations as spans', function () {
    Route::get('/cache', function () {
        Cache::put('report', ['a' => 1], 60);
        Cache::get('report');
        Cache::get('missing');
        Cache::put('api_token', 'cache_secret_value', 60);
        Cache::forget('report');

        return 'ok';
    });
    $this->get('/cache')->assertOk();

    $spans = $this->traceSpans();
    expect(array_column($this->spansOf($spans, 'cache'), 'label'))
        ->toContain('cache write report')->toContain('cache hit report')->toContain('cache miss missing')->toContain('cache forget report');
    expect($this->everythingStored())->not->toContain('cache_secret_value');
});

it('counts rows hydrated per table as tables read', function () {
    $this->seedCatalog();
    Route::get('/read', function () {
        Product::all();
        DB::table('orders')->count();

        return 'ok';
    });
    $this->get('/read')->assertOk();

    $read = $this->lastTrace()['tables_read'];
    expect($read['products'])->toEqual(['queries' => 1, 'rows' => 3])->and($read['orders']['queries'])->toBe(1);
});

it('attributes the user to the trace', function () {
    $user = $this->makeUser();
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products), $user);

    expect($this->lastTrace()['user_id'])->toBe((string) $user->id);
});

it('persists nothing for a request that is not recorded', function () {
    config(['request-tracer.mode' => 'off']);
    $products = $this->seedCatalog();
    placeOrder($this, $this->orderPayload($products))->assertCreated();

    expect($this->tracerTable('traces')->count())->toBe(0);
});

it('keeps working when the tracer itself fails', function () {
    $products = $this->seedCatalog();
    $logged = [];
    Failure::useSink(function (string $message) use (&$logged) {
        $logged[] = $message;
    });

    // 1. the recording decision blows up
    $this->app->instance(RecordingDecider::class, new class extends RecordingDecider
    {
        public function decide(Request $request): Decision
        {
            throw new RuntimeException('decider broke');
        }
    });
    placeOrder($this, $this->orderPayload($products))->assertCreated();

    // 2. recording works but storing the trace blows up (the request was already answered; the tracer must not throw)
    $this->app->forgetInstance(RecordingDecider::class);
    $this->mock(TraceStorage::class, fn ($m) => $m->shouldReceive('store')->andThrow(new RuntimeException('disk full')));
    $this->actingAs($this->makeUser())->postJson('/api/orders', ['items' => []])->assertStatus(422);

    expect(Order::count())->toBe(1)
        ->and(implode('
', $logged))->toContain('decider broke')->toContain('disk full');
});
