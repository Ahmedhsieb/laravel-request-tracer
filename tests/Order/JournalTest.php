<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Journal\Journal;
use Ahmedhsieb\RequestTracer\Journal\JournalStorage;
use Ahmedhsieb\RequestTracer\Recording\Listening;
use Ahmedhsieb\RequestTracer\Replay\Replayer;
use Ahmedhsieb\RequestTracer\Replay\ReplayRequest;
use Ahmedhsieb\RequestTracer\Storage\TableInstaller;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Order;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['request-tracer.mode' => 'triggered', 'request-tracer.journal.enabled' => true]);   // nothing is traced; the journal notes every request
    Route::get('/probe', fn () => 'pong')->name('probe');
    Route::post('/submit', fn (Request $r) => response()->json(['ok' => true]))->name('submit');
    Route::get('/boom', function () {
        throw new RuntimeException('The ledger is closed.');
    });
    Route::get('/teapot', fn () => response('no', 500));
    Route::get('/health-journal', fn () => 'ok');
});

function journalRows(object $test): array
{
    return array_map(fn (object $row): array => (array) $row, $test->tracerTable('journal')->orderBy('id')->get()->all());
}

it('notes every request in one light row, without tracing anything', function () {
    $this->get('/probe?page=2')->assertOk();

    $rows = journalRows($this);
    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['method' => 'GET', 'url' => '/probe?page=2', 'route_name' => 'probe', 'status' => 200, 'failure' => null, 'body' => null, 'trace_id' => null])
        ->and((float) $rows[0]['duration_ms'])->toBeGreaterThan(0)
        ->and($this->tracerTable('traces')->count())->toBe(0);               // a journal is not a trace
});

it('records the user a request ran as', function () {
    $user = $this->makeUser();

    $this->actingAs($user)->get('/probe')->assertOk();

    expect(journalRows($this)[0]['user_id'])->toBe((string) $user->id);
});

it('keeps the body in the form it came in, with every secret masked before it is stored', function () {
    $this->postJson('/submit', ['coupon' => 'OLD2020', 'password' => 'hunter2', 'payment' => ['card_number' => '4111111111111111', 'qty' => 2], 'note' => 'Bearer abc.def.ghi'])->assertOk();

    $row = journalRows($this)[0];
    $body = json_decode((string) $row['body'], true);
    expect($body['coupon'])->toBe('OLD2020')
        ->and($body['password'])->toBe('••••••••')
        ->and($body['payment'])->toBe(['card_number' => '••••••••', 'qty' => 2])
        ->and($body['note'])->not->toContain('abc.def.ghi')                      // a value pattern (Bearer ...) is scrubbed too
        ->and((bool) $row['masked'])->toBeTrue()
        ->and(json_encode($row))->not->toContain('hunter2')->not->toContain('4111111111111111');
});

it('stores a form post as urlencoded data, so a replay sends a form again', function () {
    $this->post('/submit', ['title' => 'Hello', 'token' => 'secret-value'])->assertOk();

    $row = journalRows($this)[0];
    parse_str((string) $row['body'], $form);
    expect($form)->toBe(['title' => 'Hello', 'token' => '••••••••'])
        ->and(json_decode((string) $row['headers'], true)['content-type'])->toContain('application/x-www-form-urlencoded');
});

it('keeps only allow-listed headers, never an authorization or a cookie', function () {
    $this->withHeaders(['Authorization' => 'Bearer top-secret', 'Cookie' => 'laravel_session=abc', 'X-Request-Id' => 'r-1', 'Accept' => 'application/json'])->get('/probe')->assertOk();

    $headers = json_decode((string) journalRows($this)[0]['headers'], true);
    expect($headers)->toMatchArray(['x-request-id' => 'r-1', 'accept' => 'application/json'])
        ->and(array_keys($headers))->not->toContain('authorization')->not->toContain('cookie');
});

it('says how a request failed: the exception, or the status', function () {
    $this->get('/boom')->assertStatus(500);
    $this->get('/teapot')->assertStatus(500);
    $this->get('/probe')->assertOk();

    $rows = journalRows($this);
    expect($rows[0])->toMatchArray(['status' => 500, 'failure' => 'RuntimeException: The ledger is closed.'])
        ->and($rows[1])->toMatchArray(['status' => 500, 'failure' => 'HTTP 500'])
        ->and($rows[2]['failure'])->toBeNull();
});

it('leaves out what is excluded, the dashboard and replays', function () {
    config(['request-tracer.exclude.routes' => ['submit']]);
    $this->get('/health-journal')->assertOk();                                  // `health*` is excluded by default
    $this->post('/submit', ['a' => 1])->assertOk();                             // by route name
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
    $this->getJson('/request-tracer/api/v1/meta')->assertOk();                  // the dashboard itself
    (new Replayer)->run(new ReplayRequest('GET', '/probe'));    // a replay is not a request that happened

    expect(journalRows($this))->toBe([]);
});

it('links a request to its trace when it was traced as well', function () {
    $file = sys_get_temp_dir().'/rt-journal-'.uniqid();
    Listening::useFile($file);
    Listening::beat();
    try {
        $this->get('/probe')->assertOk();
    } finally {
        @unlink($file);
        Listening::useFile(null);
    }

    expect(journalRows($this)[0]['trace_id'])->toBe((string) $this->lastTrace()['id']);
});

it('does nothing, and touches no table, while it is switched off', function () {
    config(['request-tracer.journal.enabled' => false]);
    Schema::dropIfExists('tracer_journal');
    TableInstaller::reset();

    $this->get('/probe')->assertOk();

    expect(Schema::hasTable('tracer_journal'))->toBeFalse();
});

it('creates its table the first time it is needed', function () {
    Schema::dropIfExists('tracer_journal');
    TableInstaller::reset();

    $this->get('/probe')->assertOk();

    expect(Schema::hasTable('tracer_journal'))->toBeTrue()->and(journalRows($this))->toHaveCount(1);
});

it('does not keep a body that is too large to replay faithfully, and says so', function () {
    config(['request-tracer.journal.body_max_bytes' => 1024]);

    $this->postJson('/submit', ['blob' => str_repeat('x', 5000)])->assertOk();

    $row = journalRows($this)[0];
    expect($row['body'])->toBeNull()->and((bool) $row['body_cut'])->toBeTrue();
});

it('trims by age and by count', function () {
    $storage = app(JournalStorage::class);
    foreach (range(1, 5) as $i) {
        $storage->store(['method' => 'GET', 'url' => "/n{$i}", 'status' => 200, 'duration_ms' => 1, 'headers' => []]);
    }
    $this->tracerTable('journal')->where('url', '/n1')->update(['created_at' => Carbon::now('UTC')->subHours(3)->format('Y-m-d H:i:s')]);

    expect($storage->trim(1, 3))->toBe(2)                                       // /n1 is too old, /n2 is beyond the newest three
        ->and(array_column(journalRows($this), 'url'))->toBe(['/n3', '/n4', '/n5']);
});

it('trims itself while requests keep coming, without a scheduler', function () {
    config(['request-tracer.journal.max_rows' => 3]);
    $storage = app(JournalStorage::class);
    foreach (range(1, 10) as $i) {
        $storage->store(['method' => 'GET', 'url' => "/n{$i}", 'status' => 200, 'duration_ms' => 1, 'headers' => []]);
    }

    app(Journal::class)->trim();

    expect(count(journalRows($this)))->toBe(3);
});

// ---------------------------------------------------------------------------------------------------- the API

it('lists the journal, newest first, with filters and a cursor', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
    $this->get('/probe')->assertOk();
    $this->get('/boom')->assertStatus(500);
    $this->get('/probe?x=1')->assertOk();

    $all = $this->getJson('/request-tracer/api/v1/journal')->assertOk()->json();
    expect(array_column($all['data'], 'url'))->toBe(['/probe?x=1', '/boom', '/probe'])
        ->and($all['data'][1])->toMatchArray(['status' => 500, 'failure' => 'RuntimeException: The ledger is closed.', 'has_body' => false])
        ->and($all['data'][0]['created_at'])->toMatch('/\+00:00$/');

    expect(array_column($this->getJson('/request-tracer/api/v1/journal?status=failed')->json('data'), 'url'))->toBe(['/boom'])
        ->and(array_column($this->getJson('/request-tracer/api/v1/journal?search=x%3D1')->json('data'), 'url'))->toBe(['/probe?x=1'])
        ->and(array_column($this->getJson('/request-tracer/api/v1/journal?status=500')->json('data'), 'url'))->toBe(['/boom']);

    $page = $this->getJson('/request-tracer/api/v1/journal?limit=2')->json();
    expect($page['data'])->toHaveCount(2)->and($page['next_cursor'])->toBeString();
    $rest = $this->getJson('/request-tracer/api/v1/journal?limit=2&cursor='.$page['next_cursor'])->json();
    expect(array_column($rest['data'], 'url'))->toBe(['/probe'])->and($rest['next_cursor'])->toBeNull();
});

it('returns one entry in full, 404 for an unknown one, and clears the journal', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);
    $this->postJson('/submit', ['coupon' => 'A1', 'password' => 'x'])->assertOk();
    $id = journalRows($this)[0]['id'];

    $entry = $this->getJson("/request-tracer/api/v1/journal/{$id}")->assertOk()->json('data');
    expect($entry['headers'])->toHaveKey('content-type')->and(json_decode($entry['body'], true))->toBe(['coupon' => 'A1', 'password' => '••••••••']);

    $this->getJson('/request-tracer/api/v1/journal/01HNOSUCHENTRY0000000000A')->assertNotFound();
    $this->deleteJson('/request-tracer/api/v1/journal')->assertOk();
    expect(journalRows($this))->toBe([]);
});

it('is behind the gate like the rest of the dashboard', function () {
    $this->getJson('/request-tracer/api/v1/journal')->assertForbidden();
    $this->deleteJson('/request-tracer/api/v1/journal')->assertForbidden();
});

it('tells the dashboard whether the journal is on', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => true);

    $this->getJson('/request-tracer/api/v1/meta')->assertJsonPath('journal', ['enabled' => true, 'hours' => 1, 'max_rows' => 500]);
});

// ---------------------------------------------------------------------------------------------------- replay from the journal

it('runs a request from the journal again, as the same user, traced, with the database left as it was', function () {
    $user = $this->makeUser();
    $products = $this->seedCatalog();
    $this->actingAs($user)->postJson('/api/orders', $this->orderPayload($products))->assertCreated();
    expect(Order::query()->count())->toBe(1);

    $entry = app(JournalStorage::class)->latest();
    $replay = Journal::toReplayRequest($entry);
    expect($replay->method)->toBe('POST')->and($replay->url)->toBe('/api/orders')->and((string) $replay->asUser)->toBe((string) $user->id);

    $result = (new Replayer)->run($replay);

    expect($result)->toMatchArray(['status' => 201, 'error' => null])
        ->and(Order::query()->count())->toBe(1)                                  // the replay's order was rolled back
        ->and($this->traceStorage()->trace($result['trace_id']))->toMatchArray(['path' => '/api/orders', 'mode' => 'replay'])
        ->and(array_column($this->traceStorage()->writes($result['trace_id']), 'table'))->toContain('orders');
});

it('replays through the command: the newest request, or the newest failure', function () {
    putenv('REQUEST_TRACER=1');
    try {
        $this->get('/boom')->assertStatus(500);
        $this->get('/probe')->assertOk();

        $this->artisan('request-tracer:replay', ['--journal' => 'failed', '--json' => true])->expectsOutputToContain('"status":500')->assertExitCode(0);
        $this->artisan('request-tracer:replay', ['--journal' => 'last', '--json' => true])->expectsOutputToContain('"status":200')->assertExitCode(0);
        $this->artisan('request-tracer:replay', ['--journal' => '01HNOSUCHENTRY0000000000A', '--json' => true])->expectsOutputToContain('There is no journal entry')->assertExitCode(1);
    } finally {
        putenv('REQUEST_TRACER');
    }

    expect($this->tracerTable('traces')->where('mode', 'replay')->count())->toBe(2);
});

it('is trimmed by the prune command too', function () {
    $storage = app(JournalStorage::class);
    foreach (range(1, 4) as $i) {
        $storage->store(['method' => 'GET', 'url' => "/n{$i}", 'status' => 200, 'duration_ms' => 1, 'headers' => []]);
    }
    config(['request-tracer.journal.max_rows' => 2]);

    $this->artisan('request-tracer:prune')->expectsOutputToContain('Trimmed 2 journal entries')->assertExitCode(0);

    expect(count(journalRows($this)))->toBe(2);
});
