<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RequestTracer\Hot;
use RequestTracer\Recording\Decision;
use RequestTracer\RecordingState;
use RequestTracer\Tests\Fixtures\Attr\AttributedService;
use RequestTracer\Tests\Fixtures\Attr\TracedClass;
use RequestTracer\Tests\Fixtures\Contracts\CurrencyProvider;
use RequestTracer\Tests\Fixtures\Http\ClosureBoundMiddleware;
use RequestTracer\Tests\Fixtures\Locked\FinalThing;
use RequestTracer\Tests\Fixtures\Locked\PartlyLocked;
use RequestTracer\Tests\Fixtures\Providers\DollarProvider;
use RequestTracer\Tests\Fixtures\Providers\EuroProvider;
use RequestTracer\Tests\Fixtures\Services\CountingService;
use RequestTracer\Tests\Fixtures\Services\OrderService;
use RequestTracer\Tests\Fixtures\Services\RateService;
use RequestTracer\Tracer;

/** @param  list<array<string, mixed>>  $spans */
function childrenOf(array $spans, int $parentId): array
{
    return array_values(array_filter($spans, fn ($s) => $s['parent_id'] === $parentId));
}

function order($test, ?array $payload = null)
{
    $products = $test->seedCatalog();

    return $test->actingAs($test->makeUser())->postJson('/api/orders', $payload ?? $test->orderPayload($products));
}

it('builds the whole flow as a tree: request > middleware > controller > services > queries / http', function () {
    order($this)->assertCreated();
    $spans = $this->traceSpans();

    $root = $spans[0];
    $controller = $this->spansOf($spans, 'controller')[0];
    $create = $this->spansOf($spans, 'service', 'OrderService::create')[0];

    $ancestors = function (array $span) use ($spans): array {
        $byId = array_column($spans, null, 'id');
        $chain = [];
        for ($p = $span['parent_id']; $p !== null; $p = $byId[$p]['parent_id']) {
            $chain[] = $byId[$p]['type'].':'.$byId[$p]['label'];
        }

        return $chain;
    };

    expect($ancestors($controller))->toContain('middleware:Authenticate')->toContain('request:POST /api/orders')
        ->and($create['parent_id'])->toBe($controller['id']);

    $labels = array_map(fn ($s) => $s['type'].':'.$s['label'], childrenOf($spans, $create['id']));
    expect($labels)->toContain('service:PricingService::subtotal')
        ->toContain('service:CouponService::apply')
        ->toContain('service:PaymentService::charge')
        ->toContain('service:InventoryService::reserve');

    // queries run inside the service that issued them
    $pricing = $this->spansOf($spans, 'service', 'PricingService::subtotal')[0];
    expect(array_column(childrenOf($spans, $pricing['id']), 'type'))->toContain('query');

    // the HTTP call is inside PaymentService::charge
    $payment = $this->spansOf($spans, 'service', 'PaymentService::charge')[0];
    expect(array_column(childrenOf($spans, $payment['id']), 'type'))->toContain('http');

    // three inventory reservations
    expect($this->spansOf($spans, 'service', 'InventoryService::reserve'))->toHaveCount(3);
});

it('captures arguments in and return value out of a service, masked and dd()-formatted', function () {
    order($this)->assertCreated();
    $create = $this->spansOf($this->traceSpans(), 'service', 'OrderService::create')[0];

    $in = collect($create['input']['i'])->mapWithKeys(fn ($p) => [$p[0] => $p[1]]);
    expect($in['user']['kind'])->toBe('model')
        ->and($in['data']['$'])->toBe('arr');

    $stored = json_encode($create['input']);
    expect($stored)->not->toContain('4111111111111111')       // card_number is masked
        ->and($stored)->not->toContain('secret-password')      // User::$hidden
        ->and($stored)->toContain('"$":"mask"');

    expect($create['output']['kind'])->toBe('model')
        ->and($create['output']['c'])->toContain('Models\Order');
});

it('flags the service that threw a caught exception and links the exception span', function () {
    order($this)->assertCreated();                           // coupon OLD2020 is expired: CouponService throws, OrderService catches
    $spans = $this->traceSpans();

    $coupon = $this->spansOf($spans, 'service', 'CouponService::apply')[0];
    expect($coupon['flag'])->toBe('caught');

    $exception = array_values(array_filter(childrenOf($spans, $coupon['id']), fn ($s) => $s['type'] === 'exception'));
    expect($exception)->toHaveCount(1)->and($exception[0]['label'])->toContain('Coupon OLD2020 has expired')
        ->and($exception[0]['flag'])->toBe('caught');

    $problems = array_column($this->traceProblems(), 'kind');
    expect($problems)->toContain('exception_caught');
    expect(collect($this->traceProblems())->firstWhere('kind', 'exception_caught')['message'])->toContain('the response was still 201');
});

it('marks services as failed when the exception ended the request', function () {
    $products = $this->seedCatalog();
    $payload = $this->orderPayload($products, ['coupon' => null]);
    $payload['items'][2]['qty'] = 99;
    $this->actingAs($this->makeUser())->postJson('/api/orders', $payload)->assertStatus(500);

    $spans = $this->traceSpans();
    expect($this->spansOf($spans, 'service', 'OrderService::create')[0]['flag'])->toBe('failed');
});

it('traces middleware with a span per handle()', function () {
    order($this)->assertCreated();

    $middleware = $this->spansOf($this->traceSpans(), 'middleware');
    expect(array_column($middleware, 'label'))->toContain('Authenticate')
        ->and(array_column($middleware, 'label'))->not->toContain('RecordRequest');   // the tracer never traces itself
});

it('does not use proxies when nothing is recorded', function () {
    config(['request-tracer.mode' => 'off']);
    $service = app(OrderService::class);

    expect($service::class)->toBe(OrderService::class);
});

it('keeps singletons shared and swaps in the proxy only while recording', function () {
    $this->app->singleton(CountingService::class);

    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $a = app(CountingService::class);
    $b = app(CountingService::class);
    app(Tracer::class)->reset();

    expect($a)->toBe($b)->and($a::class)->toContain('__Proxy')->and($a)->toBeInstanceOf(CountingService::class);
    $a->increment();
    expect($b->count)->toBe(1);
});

it('does not trace methods marked #[NoTrace]', function () {
    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $session = app(Tracer::class)->session();
    $service = app(CountingService::class);
    $service->increment();
    $service->quiet();
    $labels = array_map(fn ($s) => $s->label, $session->recorder->finish());
    app(Tracer::class)->reset();

    expect($labels)->toContain('CountingService::increment')->not->toContain('CountingService::quiet');
});

it('keeps contextual bindings working for proxied classes', function () {
    $this->app->when(RateService::class)->needs(CurrencyProvider::class)->give(EuroProvider::class);
    $this->app->bind(CurrencyProvider::class, DollarProvider::class);

    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $rate = app(RateService::class);
    app(Tracer::class)->reset();

    expect($rate::class)->toContain('__Proxy')->and($rate->currency())->toBe('EUR');
});

it('proxies the concrete class behind an interface binding', function () {
    $this->app->bind(CurrencyProvider::class, EuroProvider::class);

    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $provider = app(CurrencyProvider::class);
    app(Tracer::class)->reset();

    // EuroProvider is outside the proxied namespace: left alone
    expect($provider::class)->toBe(EuroProvider::class);

    config(['request-tracer.proxies.namespaces' => ['RequestTracer\Tests\Fixtures']]);
    $this->app->forgetInstance(CurrencyProvider::class);
    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $provider = app(CurrencyProvider::class);
    app(Tracer::class)->reset();
    expect($provider)->toBeInstanceOf(CurrencyProvider::class);
});

it('leaves classes with custom closure bindings alone', function () {
    $this->app->bind(CountingService::class, fn () => new CountingService);

    app(Tracer::class)->begin('request', new Decision(RecordingState::On));
    $service = app(CountingService::class);
    app(Tracer::class)->reset();

    expect($service::class)->toBe(CountingService::class);
});

it('says which classes and methods are not traceable', function () {
    config(['request-tracer.proxies.namespaces' => ['RequestTracer\Tests\Fixtures\Locked']]);
    Route::get('/locked', function () {
        app(FinalThing::class)->value();
        app(PartlyLocked::class)->open();

        return 'ok';
    });

    $this->get('/locked')->assertOk();

    $notes = $this->lastTrace()['notes'];
    expect(array_column($notes, 'kind'))->toContain('not_traceable');
    $messages = implode("\n", array_column($notes, 'message'));
    expect($messages)->toContain('FinalThing — not traceable: final class')
        ->toContain('PartlyLocked::sealed — not traceable: final method');
    expect(array_column($this->spansOf($this->traceSpans(), 'service'), 'label'))->toContain('PartlyLocked::open');
});

it('honours #[Trace] on a method outside the configured namespaces', function () {
    Route::get('/attr', function () {
        $s = app(AttributedService::class);

        return $s->traced(1) + $s->untraced(1) + app(TracedClass::class)->two();
    });

    $this->get('/attr')->assertOk();

    $labels = array_column($this->spansOf($this->traceSpans(), 'service'), 'label');
    expect($labels)->toContain('Custom label')                       // custom label from #[Trace(label: ...)]
        ->not->toContain('AttributedService::untraced')
        ->toContain('TracedClass::one')->toContain('TracedClass::two');   // #[Trace] on the class: every method
});

it('lets the trace() helper record any value and return it unchanged', function () {
    Route::get('/helper', function () {
        $total = trace(40 + 2, 'answer');
        $order = trace(['sku' => 'A', 'api_key' => 'sk_hidden_value'], 'payload');

        return response()->json(['total' => $total, 'order' => $order['sku']]);
    });

    $this->getJson('/helper')->assertOk()->assertJson(['total' => 42, 'order' => 'A']);

    $spans = $this->traceSpans();
    $answer = $this->spansOf($spans, 'service', 'answer')[0];
    expect($answer['output'])->toBe(42)
        ->and($answer['file'])->toContain('LayerTwoProxiesTest.php')
        ->and($answer['line'])->toBeInt()
        ->and(json_encode($this->spansOf($spans, 'service', 'payload')[0]['output']))->not->toContain('sk_hidden_value');
});

it('does not leak the hot flag or any state between requests', function () {
    order($this)->assertCreated();
    expect(Hot::$on)->toBeFalse();

    $first = $this->lastTrace();
    $this->actingAs($this->makeUser())->postJson('/api/orders', ['items' => []])->assertStatus(422);
    expect(Hot::$on)->toBeFalse();

    $second = $this->lastTrace();
    expect($second['id'])->not->toBe($first['id'])
        ->and($this->traceSpans($second['id'])[0]['label'])->toBe('POST /api/orders')
        ->and(count($this->traceSpans($second['id'])))->toBeLessThan(count($this->traceSpans($first['id'])));
});

it('records a middleware that a custom container closure builds, and still terminates it', function () {
    ClosureBoundMiddleware::$terminated = 0;
    app()->singleton(ClosureBoundMiddleware::class, fn () => new ClosureBoundMiddleware('built by closure'));
    Route::middleware(ClosureBoundMiddleware::class.':?')->get('/closure-mw', fn () => 'ok');

    $this->get('/closure-mw')->assertOk()->assertHeader('X-Closure', 'built by closure?');

    $span = collect($this->traceSpans())->firstWhere('label', 'ClosureBoundMiddleware');
    expect($span)->not->toBeNull()
        ->and($span['type'])->toBe('middleware')
        ->and($span['file'])->toContain('ClosureBoundMiddleware.php')
        ->and(ClosureBoundMiddleware::$terminated)->toBe(1);
});

it('leaves a closure-built middleware alone when nothing is recording', function () {
    ClosureBoundMiddleware::$terminated = 0;
    config(['request-tracer.mode' => 'off']);
    app()->singleton(ClosureBoundMiddleware::class, fn () => new ClosureBoundMiddleware('plain'));
    Route::middleware(ClosureBoundMiddleware::class)->get('/closure-mw', fn () => 'ok');

    $this->get('/closure-mw')->assertOk()->assertHeader('X-Closure', 'plain!');

    expect(app(ClosureBoundMiddleware::class))->toBeInstanceOf(ClosureBoundMiddleware::class)      // not decorated
        ->and(ClosureBoundMiddleware::$terminated)->toBe(1)
        ->and($this->tracerTable('traces')->count())->toBe(0);
});
