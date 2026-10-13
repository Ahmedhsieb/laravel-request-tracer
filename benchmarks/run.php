<?php

declare(strict_types=1);

/*
 * Request Tracer benchmark.
 *
 *   php benchmarks/run.php                       all scenarios, 300 requests each, prints a Markdown table
 *   php benchmarks/run.php --requests=1000       more requests
 *   php benchmarks/run.php --scenario=deep       one scenario (this is what the orchestrator runs in a child process)
 *   php benchmarks/run.php --json                machine readable output
 *
 * What it measures: the "Order flow" example application (tests/Fixtures: auth, validation, services, queries, a caught exception,
 * HTTP call, event, three tables written in a transaction) handled in-process by Laravel's HTTP kernel on a file-backed SQLite
 * database. Every scenario runs in its own PHP process. "handle" is the time until the response exists (what the user waits for),
 * "terminate" is the work the tracer defers until after the response was sent. No web server, no network: the numbers are the
 * *overhead* of the package, not absolute capacity. See docs/benchmarks.md for results and how to read them.
 */

use Ahmedhsieb\RequestTracer\Recording\Listening;
use Ahmedhsieb\RequestTracer\Recording\RecordingDecider;
use Ahmedhsieb\RequestTracer\RequestTracerServiceProvider;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Http\OrderController;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Product;
use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\Concerns\CreatesApplication;

require __DIR__.'/../vendor/autoload.php';

const SCENARIOS = [
    'absent' => 'Package not installed',
    'disabled' => 'Installed, enabled = false',
    'idle' => 'Installed, nobody is listening',
    'journal' => 'Installed, nobody is listening, the request journal is on',
    'calls' => 'Listening: every call of the project, with input and output',
    'lines' => 'Listening: + every line of the project (the default)',
];

$options = getopt('', ['scenario::', 'requests::', 'warmup::', 'json']);

if (isset($options['scenario'])) {
    echo json_encode(runScenario((string) $options['scenario'], (int) ($options['requests'] ?? 300), (int) ($options['warmup'] ?? 30)), JSON_THROW_ON_ERROR);
    exit(0);
}

// ---- orchestrator: one child process per scenario ------------------------------------------------------------------------------
$requests = (int) ($options['requests'] ?? 300);
$results = [];
foreach (SCENARIOS as $key => $label) {
    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' --scenario='.$key.' --requests='.$requests;
    fwrite(STDERR, "running {$key} ...\n");
    $output = shell_exec($command);
    $decoded = is_string($output) ? json_decode($output, true) : null;
    if (! is_array($decoded)) {
        fwrite(STDERR, "scenario {$key} failed:\n{$output}\n");
        exit(1);
    }
    $results[$key] = $decoded + ['label' => $label];
}

if (isset($options['json'])) {
    echo json_encode($results, JSON_PRETTY_PRINT), "\n";
    exit(0);
}

$base = $results['absent']['handle_median_ms'];
echo '| Scenario | handle median | handle p95 | terminate median | overhead vs. not installed | peak memory |', "\n";
echo '|---|---:|---:|---:|---:|---:|', "\n";
foreach ($results as $r) {
    $overhead = $r['handle_median_ms'] - $base;
    printf(
        "| %s | %.2f ms | %.2f ms | %.2f ms | %s | %.1f MB |\n",
        $r['label'],
        $r['handle_median_ms'],
        $r['handle_p95_ms'],
        $r['terminate_median_ms'],
        $r === $results['absent'] ? '-' : sprintf('%+.2f ms (%+.1f %%)', $overhead, $base > 0 ? $overhead / $base * 100 : 0),
        $r['peak_mb'],
    );
}
printf("\nDecision cost on a request nothing triggers (triggered mode, measured in isolation): %.1f µs.\n", $results['idle']['decide_us']);
echo "\nPHP ".PHP_VERSION.', Laravel '.Application::VERSION.', '.$requests." requests per scenario (after warm-up), SQLite file database.\n";
exit(0);

// ---- worker ---------------------------------------------------------------------------------------------------------------------

/** @return array<string, float|int> */
function runScenario(string $scenario, int $requests, int $warmup): array
{
    if (! isset(SCENARIOS[$scenario])) {
        fwrite(STDERR, "unknown scenario {$scenario}\n");
        exit(2);
    }

    $database = sys_get_temp_dir().'/rt-bench-'.getmypid().'.sqlite';
    @unlink($database);
    touch($database);

    // "somebody is listening": the heartbeat the listen command would keep touching
    $listening = in_array($scenario, ['calls', 'lines'], true);
    Listening::useFile(sys_get_temp_dir().'/rt-bench-listen-'.getmypid());
    if ($listening) {
        Listening::beat();
    }

    $factory = new class($scenario, $database)
    {
        use CreatesApplication;

        public function __construct(private readonly string $scenario, private readonly string $database) {}

        /** @return array<int, class-string> */
        protected function getPackageProviders($app): array
        {
            return $this->scenario === 'absent' ? [] : [RequestTracerServiceProvider::class];
        }

        protected function defineEnvironment($app): void
        {
            $config = $app['config'];
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('app.env', 'production');
            $config->set('app.debug', false);
            $config->set('database.default', 'bench');
            $config->set('database.connections.bench', ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => false]);
            $config->set('cache.default', 'array');
            $config->set('queue.default', 'sync');
            $config->set('logging.default', 'null');
            $config->set('auth.defaults.guard', 'web');
            $config->set('auth.guards.web', ['driver' => 'bench', 'provider' => 'users']);
            $config->set('auth.providers.users', ['driver' => 'eloquent', 'model' => User::class]);

            $config->set('request-tracer.enabled', $this->scenario !== 'disabled');
            $config->set('request-tracer.local_environments', ['local']);
            $config->set('request-tracer.mode', 'triggered');
            $config->set('request-tracer.journal.enabled', $this->scenario === 'journal');
            $config->set('request-tracer.storage.separate_connection', true);
            // the project's own code: the services of the example application (what `code.paths` => ['app'] is for a real project)
            $config->set('request-tracer.code.paths', [str_replace('\\', '/', dirname(__DIR__)).'/tests/Fixtures/Services']);
            $config->set('request-tracer.code.line_by_line', $this->scenario === 'lines');
            $config->set('request-tracer.proxies.cache_path', sys_get_temp_dir().'/rt-bench-proxies');
            $config->set('request-tracer.deep_trace.cache_path', sys_get_temp_dir().'/rt-bench-compiled');
        }

        public function make(): Application
        {
            return $this->createApplication();
        }
    };

    $app = $factory->make();
    $app['auth']->viaRequest('bench', static fn () => User::query()->first());
    $app['router']->post('/api/orders', [OrderController::class, 'store'])->middleware('auth:web');

    Artisan::call('migrate', ['--path' => dirname(__DIR__).'/tests/Fixtures/database/migrations', '--realpath' => true, '--force' => true]);
    if ($scenario !== 'absent') {
        Artisan::call('migrate', ['--path' => dirname(__DIR__).'/database/migrations', '--realpath' => true, '--force' => true]);
    }

    User::create(['name' => 'Ann', 'email' => 'ann@example.test', 'password' => 'secret', 'api_token' => 'tok', 'is_vip' => false]);
    $products = [
        Product::create(['sku' => 'A', 'name' => 'Widget', 'price' => 10, 'stock' => 1_000_000_000]),
        Product::create(['sku' => 'B', 'name' => 'Gadget', 'price' => 25, 'stock' => 1_000_000_000]),
        Product::create(['sku' => 'C', 'name' => 'Gizmo', 'price' => 40, 'stock' => 1_000_000_000]),
    ];
    Http::fake(['payments.test/*' => Http::response(['status' => 'approved', 'id' => 'ch_1'], 200)]);

    $payload = [
        'items' => [
            ['product_id' => $products[0]->id, 'qty' => 2],
            ['product_id' => $products[1]->id, 'qty' => 1],
            ['product_id' => $products[2]->id, 'qty' => 1],
        ],
        'coupon' => 'OLD2020',
        'payment_method' => 'card',
        'card_number' => '4111111111111111',
    ];

    $kernel = $app->make(Kernel::class);
    $handle = [];
    $terminate = [];
    for ($i = 0; $i < $warmup + $requests; $i++) {
        if ($listening) {
            Listening::beat();
        }
        $request = Request::create('/api/orders', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json']);

        $t0 = hrtime(true);
        $response = $kernel->handle($request);
        $t1 = hrtime(true);
        $kernel->terminate($request, $response);
        $t2 = hrtime(true);

        if ($response->getStatusCode() !== 201) {
            fwrite(STDERR, "unexpected status {$response->getStatusCode()}: ".substr((string) $response->getContent(), 0, 300)."\n");
            exit(3);
        }
        if ($i >= $warmup) {
            $handle[] = ($t1 - $t0) / 1e6;
            $terminate[] = ($t2 - $t1) / 1e6;
        }
    }

    $decideUs = 0.0;
    if ($scenario === 'idle') {
        // what the package costs on a request nobody asked it to record: the whole decision, measured in isolation
        $decider = $app->make(RecordingDecider::class);
        $request = Request::create('/api/orders', 'POST');
        $rounds = 20000;
        $t0 = hrtime(true);
        for ($i = 0; $i < $rounds; $i++) {
            $decider->decide($request);
        }
        $decideUs = (hrtime(true) - $t0) / 1e3 / $rounds;
    }

    $journalRows = $scenario === 'journal' ? (int) $app['db']->connection('bench')->table('tracer_journal')->count() : 0;
    $traces = $scenario === 'absent' || $scenario === 'disabled' ? 0 : (int) $app['db']->connection('bench')->table('tracer_traces')->count();
    $last = $traces === 0 ? null : $app['db']->connection('bench')->table('tracer_traces')->orderByDesc('id')->first(['id', 'spans_count', 'queries_count']);
    $lines = $last === null ? 0 : (int) $app['db']->connection('bench')->table('tracer_lines')->where('trace_id', $last->id)->count();
    @unlink($database);
    Listening::stop();

    return [
        'spans_per_trace' => (int) ($last->spans_count ?? 0),
        'deep_lines_per_trace' => $lines,
        'queries_per_trace' => (int) ($last->queries_count ?? 0),
        'handle_median_ms' => percentile($handle, 50),
        'handle_p95_ms' => percentile($handle, 95),
        'terminate_median_ms' => percentile($terminate, 50),
        'peak_mb' => memory_get_peak_usage(true) / 1048576,
        'decide_us' => $decideUs,
        'traces_stored' => $traces,
        'journal_rows' => $journalRows,
        'requests' => $requests,
    ];
}

/** @param  list<float>  $values */
function percentile(array $values, int $p): float
{
    sort($values);

    return $values === [] ? 0.0 : $values[(int) min(count($values) - 1, floor(count($values) * $p / 100))];
}
