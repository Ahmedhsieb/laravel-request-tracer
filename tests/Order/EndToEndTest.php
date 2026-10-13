<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\DeepTrace\Loader;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Services\OrderService;

/** 1-based line of the n-th occurrence of $needle in OrderService.php. */
function createLine(string $needle, int $nth = 1): int
{
    static $source = null;
    $source ??= str_replace("\r", '', (string) file_get_contents(__DIR__.'/../Fixtures/Services/OrderService.php'));
    $offset = -1;
    for ($i = 0; $i < $nth; $i++) {
        $offset = strpos($source, $needle, $offset + 1);
        expect($offset)->not->toBeFalse("'{$needle}' #{$nth} not found in OrderService.php");
    }

    return substr_count($source, "\n", 0, (int) $offset) + 1;
}

/**
 * @param  list<array<string, mixed>>  $rows
 * @return list<array<string, mixed>>
 */
function atLine(array $rows, int $line, ?string $kind = null): array
{
    return array_values(array_filter($rows, fn ($r) => $r['line'] === $line && ($kind === null || $r['kind'] === $kind)));
}

beforeEach(function () {
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products))->assertCreated();
    $this->trace = $this->lastTrace();
    $this->spans = $this->traceSpans();
    $this->create = $this->spansOf($this->spans, 'service', 'OrderService::create')[0];
    $this->deep = $this->traceStorage()->lines((string) $this->trace['id'], (int) $this->create['id']);
});

it('compiled OrderService for Deep trace (the premise of this test)', function () {
    expect(str_replace('\\', '/', (string) (new ReflectionClass(OrderService::class))->getFileName()))
        ->toContain(str_replace('\\', '/', Loader::cachePath()));      // fails when OrderService was autoloaded before Deep trace was configured
});

it('records the whole flow of POST /api/orders as a tree', function () {
    $spans = $this->spans;
    $byParent = [];
    foreach ($spans as $s) {
        $byParent[$s['parent_id'] ?? 0][] = $s['type'].':'.$s['label'];
    }

    expect($this->trace)->toMatchArray(['method' => 'POST', 'path' => '/api/orders', 'status' => 201, 'route_name' => 'orders.store']);
    expect($spans[0]['label'])->toBe('POST /api/orders');

    $controller = $this->spansOf($spans, 'controller')[0];
    expect($this->create['parent_id'])->toBe($controller['id']);

    $inside = $byParent[$this->create['id']];
    expect($inside)->toContain('service:PricingService::subtotal')
        ->toContain('service:CouponService::apply')
        ->toContain('service:PaymentService::charge')
        ->toContain('event:Ahmedhsieb\RequestTracer\Tests\Fixtures\Events\OrderPlaced')
        ->toContain('model:Order #1 created')
        ->toContain('service:InventoryService::reserve');
    expect(array_count_values($inside)['service:InventoryService::reserve'])->toBe(3);

    // the one-line description the dashboard shows under the step
    expect($this->create['meta']['summary'])->toStartWith('Returns Order #');

    // per-step input / output in dd() format
    expect($this->create['input']['i'][0][0])->toBe('user')
        ->and($this->create['output']['kind'])->toBe('model');
    $pricing = $this->spansOf($spans, 'service', 'PricingService::subtotal')[0];
    expect($pricing['output'])->toBe(85.0)->and($pricing['input']['i'][0][0])->toBe('lines');
});

it('records the three tables written, with rows and before/after', function () {
    $writes = $this->traceWrites();
    $tables = array_values(array_unique(array_column($writes, 'table')));
    sort($tables);

    expect($tables)->toBe(['order_items', 'orders', 'products'])
        ->and(array_column($this->trace['tables_written'], 'table'))->toEqualCanonicalizing(['orders', 'order_items', 'products']);

    $stock = array_values(array_filter($writes, fn ($w) => $w['table'] === 'products' && $w['operation'] === 'update'));
    expect($stock)->toHaveCount(3)
        ->and($stock[0]['before']['stock'])->toBe(100)->and($stock[0]['after']['stock'])->toBe(98)
        ->and($stock[0]['transaction'])->toBe('committed');
    expect(array_values(array_filter($writes, fn ($w) => $w['table'] === 'order_items')))->toHaveCount(3);
});

it('records the caught exception and flags the step that threw it', function () {
    $coupon = $this->spansOf($this->spans, 'service', 'CouponService::apply')[0];
    $problem = collect($this->traceProblems())->firstWhere('kind', 'exception_caught');

    expect($coupon['flag'])->toBe('caught')
        ->and($problem['message'])->toContain('Coupon OLD2020 has expired')->toContain('the response was still 201')
        ->and(collect($this->traceProblems())->where('kind', 'exception_uncaught'))->toBeEmpty();
});

it('flags the identical statement that ran three times in a loop', function () {
    $problem = collect($this->traceProblems())->first(fn ($p) => $p['kind'] === 'repeated_query' && str_contains($p['message'], 'UPDATE'));

    expect($problem['message'])->toContain('same UPDATE ran 3 times')->toContain('products')
        ->and($problem['file'])->toContain('InventoryService.php');
});

// ---------------------------------------------------------------------------------------------------------------------
// Deep trace: the conditions in OrderService::create()
// ---------------------------------------------------------------------------------------------------------------------

it('Deep trace: the coupon condition was true, the try block ran, the catch block received the exception', function () {
    $line = createLine("if (! empty(\$data['coupon'])) {");
    $conds = array_column(atLine($this->deep, $line, 'cond'), null, 'snippet');

    expect($conds["! empty(\$data['coupon'])"]['result']['true'])->toBe(1)
        ->and($conds["empty(\$data['coupon'])"]['result']['false'])->toBe(1);

    expect(atLine($this->deep, createLine('try {'), 'block')[0]['hits'])->toBe(1);
    $catch = atLine($this->deep, createLine('} catch (CouponException $e) {'), 'block')[0];
    expect($catch['hits'])->toBe(1)
        ->and(json_encode($catch['result']['caught']))->toContain('Coupon OLD2020 has expired');

    expect(atLine($this->deep, createLine('$couponNote = $e->getMessage();'), 'assign')[0]['result']['last'])->toBe('Coupon OLD2020 has expired');
});

it('Deep trace: the elseif and else branches did not run', function () {
    expect(atLine($this->deep, createLine('} elseif ($subtotal > 500) {')))->toBeEmpty()           // never evaluated: "not run"
        ->and(atLine($this->deep, createLine('$discount = $this->pricing->bulkDiscount($subtotal);')))->toBeEmpty()
        ->and(atLine($this->deep, createLine('$discount = 0.0;', 2)))->toBeEmpty();                 // the else branch
});

it('Deep trace: ternary, guard clause and their operands', function () {
    $vip = atLine($this->deep, createLine('$total = $user->is_vip ?'), 'cond');
    expect($vip[0]['result']['false'])->toBe(1);
    expect(atLine($this->deep, createLine('$total = $user->is_vip ?'), 'assign')[0]['result']['last'])->toBe(85.0);

    $guard = createLine('if ($total <= 0 || count($lines) === 0) {');
    $parts = array_column(atLine($this->deep, $guard, 'cond'), null, 'snippet');
    expect($parts['$total <= 0 || count($lines) === 0']['result']['false'])->toBe(1)
        ->and($parts['$total <= 0']['result']['samples'][0])->toMatchArray(['a' => 85.0, 'b' => 0, 'op' => '<=', 'result' => false])
        ->and($parts['count($lines) === 0']['result']['samples'][0])->toMatchArray(['a' => 3, 'b' => 0, 'op' => '===', 'result' => false]);
    expect(atLine($this->deep, createLine("throw new InvalidArgumentException('Order total must be positive.');")))->toBeEmpty();
});

it('Deep trace: the loop inside the transaction closure', function () {
    $loop = atLine($this->deep, createLine('foreach ($lines as $line) {') + 1, 'loop')[0];
    expect($loop['result']['iterations'])->toBe(3)->and($loop['result']['snapshots'])->toHaveCount(3);

    $stock = atLine($this->deep, createLine('if ($product->stock < $line[\'qty\']) {'), 'cond')[0];
    expect($stock['result']['false'])->toBe(3)
        ->and($stock['result']['samples'][0])->toMatchArray(['a' => 100, 'b' => 2, 'op' => '<', 'result' => false])
        ->and($stock['result']['samples'][2])->toMatchArray(['a' => 5, 'b' => 1]);
    expect(atLine($this->deep, createLine('throw new OutOfStockException('), 'throw'))->toBeEmpty();
});

it('Deep trace: the match chose the "approved" arm', function () {
    $subject = atLine($this->deep, createLine('$status = match ($payment[\'status\']) {'), 'cond')[0];
    expect($subject['result']['values'])->toBe(['approved' => 1]);

    $approved = atLine($this->deep, createLine("'approved' => 'paid',"), 'block')[0];
    expect($approved['hits'])->toBe(1)->and($approved['result']['last'])->toBe('paid');
    expect(atLine($this->deep, createLine("'declined' => 'declined',")))->toBeEmpty()
        ->and(atLine($this->deep, createLine("default => 'pending',")))->toBeEmpty();
});

it('Deep trace: arguments and return value of create()', function () {
    $enter = array_values(array_filter($this->deep, fn ($r) => $r['kind'] === 'assign' && ($r['snippet'] ?? '') === 'arguments' && $r['result']['fn'][0] === 'OrderService::create'));
    expect($enter)->toHaveCount(1);
    $names = array_column($enter[0]['result']['first']['i'], 0);
    expect($names)->toBe(['user', 'data']);

    $return = atLine($this->deep, createLine('return $order;', 2), 'return')[0];
    expect($return['result']['value']['kind'])->toBe('model');
});

it('Deep trace data is stored once per probe and never contains a secret', function () {
    expect(count($this->deep))->toBeLessThan(80);               // a probe is one row, not one row per hit
    $stored = json_encode($this->deep);
    expect($stored)->not->toContain('4111111111111111')         // the card number argument
        ->and($stored)->not->toContain('secret-password');
});

it('a second request produces its own trace without leaking the first', function () {
    $first = $this->trace;
    $this->actingAs($this->makeUser())->postJson('/api/orders', ['items' => []])->assertStatus(422);
    $second = $this->lastTrace();

    expect($second['id'])->not->toBe($first['id'])
        ->and($this->traceStorage()->lines((string) $second['id']))->toBeEmpty();     // validation failed before OrderService ran
});
