<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Support\Paths;

const API = '/request-tracer/api/v1';

function allowEveryone(): void
{
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
}

/** Record one order request and return its trace id. */
function recordOrder($test, array $extra = []): string
{
    $products = $test->seedCatalog();
    $test->actingAs($test->makeUser())->postJson('/api/orders', $test->orderPayload($products, $extra))->assertCreated();

    return (string) $test->lastTrace()['id'];
}

// ---------------------------------------------------------------------------------------------------------------------
// access
// ---------------------------------------------------------------------------------------------------------------------

it('denies the dashboard and every API route outside the local environment unless the app defines the gate', function () {
    $this->get('/request-tracer')->assertForbidden();
    $this->getJson(API.'/meta')->assertForbidden();
    $this->getJson(API.'/traces')->assertForbidden();
    $this->postJson(API.'/tokens')->assertForbidden();
    $this->deleteJson(API.'/traces')->assertForbidden();
});

it('lets the gate decide who may look', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => $user !== null && $user->email === 'boss@example.test');

    $this->getJson(API.'/meta')->assertForbidden();                                                    // guest

    $outsider = $this->makeUser();
    $this->actingAs($outsider)->getJson(API.'/meta')->assertForbidden();

    $boss = $this->makeUser();
    $boss->email = 'boss@example.test';
    $boss->save();
    $this->actingAs($boss)->getJson(API.'/meta')->assertOk();
    $this->actingAs($boss)->get('/request-tracer')->assertOk();
});

it('never records the dashboard itself', function () {
    allowEveryone();
    $this->getJson(API.'/meta')->assertOk();
    $this->get('/request-tracer')->assertOk();

    expect($this->tracerTable('traces')->count())->toBe(0);
});

it('renders the dashboard shell with a bootstrap object and asset URLs', function () {
    allowEveryone();

    $this->get('/request-tracer')->assertOk()
        ->assertSee('<div id="app"></div>', false)
        ->assertSee('window.__REQUEST_TRACER__', false)
        ->assertSee('/request-tracer/assets/app.js', false)
        ->assertSee('"apiUrl":"\/request-tracer\/api\/v1"', false);
    $this->get('/request-tracer/trace/01abc')->assertOk()->assertSee('<div id="app"></div>', false);     // client-side route
});

it('serves package assets safely', function () {
    allowEveryone();

    $this->get('/request-tracer/assets/favicon.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    $this->get('/request-tracer/assets/missing.js')->assertNotFound();
    $this->get('/request-tracer/assets/..%2F..%2Fcomposer.json')->assertNotFound();
    $this->get('/request-tracer/assets/%2e%2e/composer.json')->assertNotFound();
});

// ---------------------------------------------------------------------------------------------------------------------
// meta
// ---------------------------------------------------------------------------------------------------------------------

it('reports environment, recording mode and the signed-in user', function () {
    allowEveryone();
    $user = $this->makeUser();

    $meta = $this->actingAs($user)->getJson(API.'/meta')->assertOk()->json();

    expect($meta['environment'])->toBe('testing')
        ->and($meta['is_production'])->toBeTrue()                           // anything that is not a "local" environment shows the badge
        ->and($meta['mode'])->toBe('always')
        ->and($meta['recording']['header'])->toBe('X-Request-Tracer')
        ->and($meta['user']['id'])->toBe($user->id)
        ->and($meta['mask'])->toBe('••••••••');
});

// ---------------------------------------------------------------------------------------------------------------------
// reading traces
// ---------------------------------------------------------------------------------------------------------------------

it('lists traces newest first with cursor pagination and filters', function () {
    allowEveryone();
    Route::get('/fast', fn () => 'ok');
    Route::get('/boom', fn () => abort(500));
    $this->get('/fast');
    $this->get('/boom');
    $this->get('/fast');

    $all = $this->getJson(API.'/traces')->assertOk()->json();
    expect($all['data'])->toHaveCount(3)->and(array_column($all['data'], 'path'))->toBe(['/fast', '/boom', '/fast']);

    $page1 = $this->getJson(API.'/traces?limit=2')->json();
    expect($page1['data'])->toHaveCount(2)->and($page1['next_cursor'])->not->toBeNull();
    $page2 = $this->getJson(API.'/traces?limit=2&cursor='.$page1['next_cursor'])->json();
    expect($page2['data'])->toHaveCount(1)->and($page2['next_cursor'])->toBeNull();

    expect($this->getJson(API.'/traces?status=5xx')->json('data'))->toHaveCount(1)
        ->and($this->getJson(API.'/traces?status=200')->json('data'))->toHaveCount(2)
        ->and($this->getJson(API.'/traces?search=boom')->json('data'))->toHaveCount(1)
        ->and($this->getJson(API.'/traces?method=POST')->json('data'))->toHaveCount(0);
});

it('filters traces that have problems and shows their problem kinds in the list', function () {
    allowEveryone();
    recordOrder($this);                                                                     // caught exception + repeated UPDATE
    Route::get('/clean', fn () => 'ok');
    $this->get('/clean');

    $withProblems = $this->getJson(API.'/traces?has_problems=1')->json('data');
    expect($withProblems)->toHaveCount(1)
        ->and($withProblems[0]['problem_kinds'])->toContain('exception_caught')->toContain('repeated_query')
        ->and($withProblems[0]['problems_count'])->toBeGreaterThan(1);
    expect($this->getJson(API.'/traces')->json('data.0.problem_kinds'))->toBe([]);
});

it('returns the step tree without payloads and the payload of one step on demand', function () {
    allowEveryone();
    $id = recordOrder($this);

    $spans = $this->getJson(API."/traces/{$id}/spans")->assertOk()->json('data');
    expect($spans[0]['type'])->toBe('request')
        ->and($spans[0])->not->toHaveKey('input')->not->toHaveKey('output')                    // the tree never ships payloads
        ->and($spans[0]['has_input'])->toBeTrue();

    $create = collect($spans)->firstWhere('label', 'OrderService::create');
    $payload = $this->getJson(API."/traces/{$id}/spans/{$create['id']}")->assertOk()->json('data');
    expect($payload['input']['value']['$'])->toBe('arr')
        ->and($payload['output']['value']['kind'])->toBe('model');

    $this->getJson(API."/traces/{$id}/spans/99999")->assertNotFound();
});

it('returns the trace summary, writes and problems', function () {
    allowEveryone();
    $id = recordOrder($this);

    $trace = $this->getJson(API."/traces/{$id}")->assertOk()->json('data');
    expect($trace)->toMatchArray(['id' => $id, 'method' => 'POST', 'path' => '/api/orders', 'status' => 201])
        ->and(array_column($trace['tables_written'], 'table'))->toEqualCanonicalizing(['orders', 'order_items', 'products']);

    $writes = $this->getJson(API."/traces/{$id}/writes")->json('data');
    $stock = collect($writes)->where('table', 'products')->where('operation', 'update')->first();
    expect($stock['before']['stock'])->toBe(100)->and($stock['after']['stock'])->toBe(98);
    expect(array_column($this->getJson(API."/traces/{$id}/problems")->json('data'), 'kind'))->toContain('exception_caught');
});

it('returns Deep trace lines of a step together with the source of the functions that ran', function () {
    allowEveryone();
    $id = recordOrder($this);
    $spans = $this->getJson(API."/traces/{$id}/spans")->json('data');
    $create = collect($spans)->firstWhere('label', 'OrderService::create');

    $data = $this->getJson(API."/traces/{$id}/spans/{$create['id']}/lines")->assertOk()->json('data');

    expect($data['lines'])->not->toBeEmpty()->and($data['sources'])->not->toBeEmpty();
    $source = collect($data['sources'])->firstWhere('name', 'OrderService::create');
    expect($source['file'])->toEndWith('OrderService.php')
        ->and($source['lines'][0])->toHaveKeys(['n', 'text'])
        ->and(collect($source['lines'])->pluck('text')->implode("\n"))->toContain('public function create(User $user, array $data): Order')
        ->and($source['changed_since_trace'])->toBeFalse();

    // the lines that carry a probe: only those can be shown as "not run"
    $statements = $source['statements'];
    expect($statements)->toBeArray()->not->toBeEmpty()
        ->and($statements)->toBe(array_values(array_unique($statements)))
        ->and(min($statements))->toBeGreaterThanOrEqual($source['start'])
        ->and(max($statements))->toBeLessThanOrEqual($source['end']);
    foreach ($data['lines'] as $row) {
        if ($row['file'] === $source['file'] && ($row['result']['fn'][1] ?? null) === $source['start'] && ($row['result']['fn'][2] ?? null) === $source['end']) {
            expect($statements)->toContain($row['line']);          // every line that ran has a probe
        }
    }

    // the whole trace's lines, too
    expect($this->getJson(API."/traces/{$id}/lines")->json('data'))->not->toBeEmpty();
});

it('only reads source files that belong to the trace', function () {
    allowEveryone();
    $id = recordOrder($this);
    $spans = $this->getJson(API."/traces/{$id}/spans")->json('data');
    $controller = collect($spans)->firstWhere('type', 'controller');

    $data = $this->getJson(API."/traces/{$id}/spans/{$controller['id']}/lines")->json('data');

    expect($data['sources'])->toBe([])->and($data['lines'])->toBe([]);                      // no Deep trace data, so no file is read
});

it('answers 404 for unknown traces', function () {
    allowEveryone();

    foreach (['', '/spans', '/writes', '/problems', '/lines', '/spans/1', '/spans/1/lines'] as $suffix) {
        $this->getJson(API.'/traces/01hnotexisting'.$suffix)->assertNotFound();
    }
});

it('deletes one trace or all of them', function () {
    allowEveryone();
    $id = recordOrder($this);
    Route::get('/more', fn () => 'ok');
    $this->get('/more');

    $this->deleteJson(API."/traces/{$id}")->assertOk()->assertJson(['deleted' => 1]);
    $this->getJson(API."/traces/{$id}")->assertNotFound();
    expect($this->tracerTable('spans')->where('trace_id', $id)->count())->toBe(0);
    $this->deleteJson(API."/traces/{$id}")->assertNotFound();

    $this->deleteJson(API.'/traces')->assertOk()->assertJson(['deleted' => 1]);
    expect($this->tracerTable('traces')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------------------------------
// tools
// ---------------------------------------------------------------------------------------------------------------------

it('issues signed tokens from the dashboard', function () {
    allowEveryone();

    $data = $this->postJson(API.'/tokens', ['ttl' => 120, 'route' => 'api/*'])->assertCreated()->json('data');

    expect($data['token'])->toStartWith('rt1.')->and($data['header'])->toBe('X-Request-Tracer')->and($data['example'])->toContain($data['token']);
    $this->postJson(API.'/tokens', ['ttl' => 99999])->assertStatus(422);
    $this->postJson(API.'/tokens', ['ttl' => 5])->assertStatus(422);

    // and the token really works
    config(['request-tracer.mode' => 'triggered']);
    Route::get('/traced-by-token', fn () => 'ok');
    $this->withHeader('X-Request-Tracer', $this->postJson(API.'/tokens', [])->json('data.token'))->get('/traced-by-token')->assertOk();
    expect($this->tracerTable('traces')->where('path', '/traced-by-token')->count())->toBe(1);
});

it('manages the watch list', function () {
    allowEveryone();

    $this->postJson(API.'/watch', ['user_id' => '42', 'minutes' => 15])->assertCreated()->assertJsonPath('data.0.user_id', '42');
    expect($this->getJson(API.'/watch')->json('data'))->toHaveCount(1);
    $this->postJson(API.'/watch', [])->assertStatus(422);
    $this->postJson(API.'/watch', ['user_id' => '43', 'minutes' => 100000])->assertStatus(422);
    $this->deleteJson(API.'/watch/42')->assertOk();
    expect($this->getJson(API.'/watch')->json('data'))->toBe([]);
});

it('queues a file for Deep trace, refuses anything outside the application, and lists the queue', function () {
    allowEveryone();
    $file = 'tests/Fixtures/Services/PricingService.php';
    app()->setBasePath(dirname(__DIR__, 2));                                                // the fixture app lives in this repository
    Paths::configure(dirname(__DIR__, 2));

    $this->postJson(API.'/deep-trace/queue', ['file' => $file])->assertCreated()->assertJsonPath('data.0.file', 'tests/Fixtures/Services/PricingService.php');
    expect(Loader::queue()->read())->toHaveCount(1);
    expect($this->getJson(API.'/deep-trace/queue')->json('data'))->toHaveCount(1);

    foreach (['../../etc/passwd', 'composer.json', 'vendor/autoload.php', 'tests/Fixtures/nope.php', ''] as $bad) {
        $this->postJson(API.'/deep-trace/queue', ['file' => $bad])->assertStatus(422);
    }

    $this->deleteJson(API.'/deep-trace/queue', ['file' => $file])->assertOk();
    expect(Loader::queue()->read())->toBe([]);
});
