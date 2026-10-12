<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RequestTracer\Http\Controllers\TraceController;
use RequestTracer\Support\Paths;
use RequestTracer\Tests\Fixtures\Http\PlainController;
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
    config(['request-tracer.proxies.namespaces' => ['RequestTracer\Tests\Fixtures\Services', 'RequestTracer\Tests\Fixtures\Http']]);
    Route::get('/vouchers/{id}', [PlainController::class, 'show']);

    $this->get('/vouchers/1057')->assertOk();

    $spans = $this->traceSpans();
    $controllers = $this->spansOf($spans, 'controller');
    expect($controllers)->toHaveCount(1)
        ->and($this->spansOf($spans, 'service', 'PlainController::show'))->toBe([])             // not a second step for the same call
        ->and(json_encode($controllers[0]['input']))->toContain('1057', 'arguments');           // what the proxy would have recorded is kept
});

it('shows the text of the action of a step that was not traced line by line, marked as such', function () {
    $dir = base_path('packages/Accounting/src/Controllers');
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

it('keeps the lines where they were when the file is newer but the function did not move', function () {
    $products = $this->seedCatalog();
    $this->actingAs($this->makeUser())->postJson('/api/orders', $this->orderPayload($products, []))->assertCreated();
    $id = (string) $this->lastTrace()['id'];
    $create = collect($this->getJson("/request-tracer/api/v1/traces/{$id}/spans")->json('data'))->firstWhere('label', 'OrderService::create');
    $service = Paths::absolute($create['file']);
    $before = $this->getJson("/request-tracer/api/v1/traces/{$id}/spans/{$create['id']}/lines")->json('data');

    $mtime = filemtime($service);
    touch($service, time() + 120);
    try {
        $after = $this->getJson("/request-tracer/api/v1/traces/{$id}/spans/{$create['id']}/lines")->json('data');
    } finally {
        touch($service, $mtime);
    }

    $source = collect($after['sources'])->firstWhere('name', 'OrderService::create');
    expect($source['changed_since_trace'])->toBeTrue()
        ->and($source['shift'])->toBe(0)
        ->and($after['lines'])->toBe($before['lines'])
        ->and(collect($before['sources'])->firstWhere('name', 'OrderService::create')['shift'])->toBe(0);
});
