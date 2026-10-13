<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Http\Controllers\TraceController;
use Ahmedhsieb\RequestTracer\Support\Paths;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Http\PlainController;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tmp\Accounting\UntracedVoucherController;

beforeEach(function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
    app('view')->getFinder()->addLocation(__DIR__.'/../Fixtures/views');
});

it('shows the data a controller handed to its view, not the rendered HTML', function () {
    Route::get('/receipt', fn () => view('receipt', ['number' => 1057, 'total' => 99.5, 'password' => 'hunter2', 'items' => [['sku' => 'A1', 'qty' => 2]]]));

    $this->get('/receipt')->assertOk();

    $controller = $this->spansOf($this->traceSpans(), 'controller')[0];
    $output = json_encode($controller['output']);
    expect($controller['meta']['summary'])->toBe('Returns view receipt')
        ->and($controller['meta']['view'])->toBe('receipt')
        ->and($output)->toContain('1057', 'A1', 'items', 'qty')
        ->and($output)->not->toContain('<html>')                       // the rendered markup is not the output
        ->and($output)->not->toContain('hunter2')                      // values are masked like everywhere else
        ->and($output)->not->toContain('__env');
});

it('keeps the plain response for a controller that does not return a view', function () {
    Route::get('/plain', fn () => response()->json(['ok' => true]));

    $this->get('/plain')->assertOk();

    $controller = $this->spansOf($this->traceSpans(), 'controller')[0];
    expect($controller['meta']['summary'])->toStartWith('Returns 200')
        ->and(json_encode($controller['output']))->toContain('JsonResponse');
});

it('lists the function of the step itself first, then the others in the order they ran', function () {
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products, []))->assertCreated();
    $id = (string) $this->lastTrace()['id'];
    $spans = $this->getJson("/request-tracer/api/v1/traces/{$id}/spans")->json('data');
    $create = collect($spans)->firstWhere('label', 'OrderService::create');

    $sources = $this->getJson("/request-tracer/api/v1/traces/{$id}/spans/{$create['id']}/lines")->json('data.sources');

    expect($sources[0]['name'])->toBe('OrderService::create')
        ->and($sources[0]['own'])->toBeTrue()
        ->and(collect($sources)->skip(1)->pluck('own')->unique()->all())->toBe([false]);
});

it('shows the action of a controller that Laravel does not know as one once, as the controller step', function () {
    config(['request-tracer.proxies.namespaces' => ['Ahmedhsieb\RequestTracer\Tests\Fixtures\Services', 'Ahmedhsieb\RequestTracer\Tests\Fixtures\Http']]);
    Route::get('/vouchers/{id}', [PlainController::class, 'show']);

    $this->get('/vouchers/1057')->assertOk();

    $spans = $this->traceSpans();
    $controllers = $this->spansOf($spans, 'controller');
    expect($controllers)->toHaveCount(1)
        ->and($this->spansOf($spans, 'service', 'PlainController::show'))->toBe([])             // not a second step for the same call
        ->and(json_encode($controllers[0]['input']))->toContain('1057', 'arguments');           // what the proxy would have recorded is kept
});

it('shows the text of the action of a step that was not traced line by line, marked as such', function () {
    $dir = base_path('packages/Accounting'.getmypid().'/src/Controllers');
    @mkdir($dir, 0777, true);
    $file = $dir.'/UntracedVoucherController.php';
    file_put_contents($file, <<<'PHP'
<?php

namespace Tmp\Accounting;

class UntracedVoucherController
{
    public function show(string $id): array
    {
        $label = 'Voucher #'.$id;

        return ['id' => $id, 'label' => $label];
    }
}
PHP);
    try {
        require_once $file;
        Route::get('/untraced/{id}', [UntracedVoucherController::class, 'show']);

        $this->get('/untraced/1057')->assertOk();

        $id = (string) $this->lastTrace()['id'];
        $controller = $this->spansOf($this->traceSpans(), 'controller')[0];
        $sources = $this->getJson("/request-tracer/api/v1/traces/{$id}/spans/{$controller['id']}/lines")->assertOk()->json('data.sources');

        expect($sources)->toHaveCount(1)
            ->and($sources[0])->toMatchArray(['name' => 'UntracedVoucherController::show', 'own' => true, 'untraced' => true, 'statements' => null])
            ->and(collect($sources[0]['lines'])->pluck('text')->implode("\n"))->toContain("'Voucher #'.\$id")
            ->and($sources[0]['start'])->toBe(7)->and($sources[0]['end'])->toBe(12);
    } finally {
        @unlink($file);
        @rmdir($dir);
        @rmdir(dirname($dir));
        @rmdir(dirname($dir, 2));
    }
});

/** The private helper that finds out how far a function moved, called directly with a file as it is now. */
function shiftOf(array $probes, array $file, string $mask = '••••••••'): ?int
{
    $call = Closure::bind(fn () => static::shiftFor($probes, $file, $mask), null, TraceController::class);

    return $call();
}

it('follows a function that moved because the file was edited after the request', function () {
    $file = [
        '<?php',
        '',
        '// a line that was added above the function after the request was traced',
        'public function show(Payment $payment)',
        '{',
        '    $payment->loadMissing([',
        "        'currency',",
        '    ]);',
        '',
        '    $debitData = $this->decode($payment->debit_data);',
        '    $debitMeta = $this->decode($payment->debit_meta);',
        '    return view(\'x\');',
        '}',
    ];
    // as it was recorded: one line higher
    $probes = [
        [3, 'public function show(Payment $payment)'],
        [4, 'arguments'],
        [5, '$payment->loadMissing([ …'],
        [9, '$debitData = $this->decode($payment->debit_data);'],
        [10, '$debitMeta = $this->decode($payment->debit_meta);'],
        [11, "return view('x');"],
    ];

    expect(shiftOf($probes, $file))->toBe(1)
        ->and(shiftOf(array_map(fn ($p) => [$p[0] + 1, $p[1]], $probes), $file))->toBe(0)       // not edited: stays
        ->and(shiftOf([[3, 'a statement that is nowhere in the file now']], $file))->toBeNull()  // nothing to go by: says so
        ->and(shiftOf([[9, "return 'a-secret-literal-••••••••';"]], $file))->toBeNull();         // masked snippets are not matched
});

it('moves the rows of a function with the edit, and leaves them alone when it did not move', function () {
    $dir = base_path('packages/Shift'.getmypid());
    @mkdir($dir, 0777, true);
    $file = $dir.'/Moved.php';
    file_put_contents($file, <<<'PHP'
<?php

// added after the request
class Moved
{
    public function show(string $id): string
    {
        $label = 'Voucher #'.$id;

        return $label;
    }
}

PHP);
    $relative = Paths::relative($file);
    $rows = function (int $start) use ($relative): array {
        $fn = ['Moved::show', $start, $start + 5];

        return [
            ['span_id' => 1, 'file' => $relative, 'line' => $start + 2, 'kind' => 'assign', 'hits' => 1, 'result' => ['seq' => 1, 'fn' => $fn], 'snippet' => "\$label = 'Voucher #'.\$id;"],
            ['span_id' => 1, 'file' => $relative, 'line' => $start + 4, 'kind' => 'return', 'hits' => 1, 'result' => ['seq' => 2, 'fn' => $fn], 'snippet' => 'return $label;'],
        ];
    };
    $sources = function (array &$lines) {
        $call = Closure::bind(function () use (&$lines) {
            return $this->sources($lines, '2000-01-01 00:00:00', null);
        }, app(TraceController::class), TraceController::class);

        return $call();
    };

    try {
        // recorded one line higher than the file has it now (the comment line was added above)
        $moved = $rows(5);
        $result = $sources($moved);

        expect($result[0])->toMatchArray(['name' => 'Moved::show', 'start' => 6, 'end' => 11, 'shift' => 1, 'changed_since_trace' => true])
            ->and(array_column($moved, 'line'))->toBe([8, 10])
            ->and($moved[0]['result']['fn'])->toBe(['Moved::show', 6, 11])
            ->and(collect($result[0]['lines'])->firstWhere('n', 8)['text'])->toContain("\$label = 'Voucher #'");

        // recorded where the file has it: nothing moves
        $still = $rows(6);
        $same = $sources($still);

        expect($same[0])->toMatchArray(['start' => 6, 'shift' => 0, 'changed_since_trace' => true])
            ->and(array_column($still, 'line'))->toBe([8, 10]);
    } finally {
        @unlink($file);
        @rmdir($dir);
        @rmdir(dirname($dir));
    }
});
