<?php

declare(strict_types=1);

namespace RequestTracer;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RequestTracer\Dumper\DumpLimits;
use RequestTracer\Dumper\Masker;
use RequestTracer\Dumper\ValueDumper;
use RequestTracer\Recording\Decision;
use RequestTracer\Recording\RecordingDecider;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\Support\App;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Summary;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Entry point of the package: owns the current TraceSession and the on/off flag every collector checks.
 *
 * `$on` is THE hot-path flag: when it is false each intercepted call costs exactly one boolean check.
 */
final class Tracer
{
    /** The singleton, for places that have no container (trace() helper, probes). Replaced on every application boot. */
    public static ?self $instance = null;

    /** True while a trace is being recorded (state On or Tentative). */
    public bool $on = false;

    private ?TraceSession $session = null;

    private ?Masker $masker = null;

    private ?DumpLimits $limits = null;

    /** @var array<string, Closure|null> */
    private array $resolvers = ['tenant' => null, 'module' => null, 'user' => null];

    public function __construct()
    {
        self::$instance = $this;
    }

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    public function recording(): bool
    {
        return $this->on;
    }

    public function session(): ?TraceSession
    {
        return $this->session;
    }

    /** @return mixed value of a `request-tracer.*` config key */
    public function config(string $key, mixed $default = null): mixed
    {
        return App::config()->get('request-tracer.'.$key, $default);
    }

    public function decider(): RecordingDecider
    {
        return App::container()->make(RecordingDecider::class);
    }

    public function storage(): TraceStorage
    {
        return App::container()->make(TraceStorage::class);
    }

    public function masker(): Masker
    {
        return $this->masker ??= Masker::fromConfig((array) $this->config('masking', []));
    }

    public function limits(): DumpLimits
    {
        return $this->limits ??= DumpLimits::fromConfig((array) $this->config('dumper', []));
    }

    /** Forget cached derived objects (after config changes, mostly in tests). */
    public function flushCaches(): void
    {
        $this->masker = null;
        $this->limits = null;
    }

    public function resolveTenantUsing(?callable $callback): void
    {
        $this->resolvers['tenant'] = $callback === null ? null : Closure::fromCallable($callback);
    }

    public function resolveModuleUsing(?callable $callback): void
    {
        $this->resolvers['module'] = $callback === null ? null : Closure::fromCallable($callback);
    }

    public function resolveUserUsing(?callable $callback): void
    {
        $this->resolvers['user'] = $callback === null ? null : Closure::fromCallable($callback);
    }

    // ---------------------------------------------------------------------------------------------
    // Lifecycle
    // ---------------------------------------------------------------------------------------------

    /**
     * Start recording a trace (request, job, command...). Any previous, unfinished session is dropped first,
     * so a crashed request can never leak state into the next one in a long-lived worker.
     */
    public function begin(string $kind, Decision $decision, ?float $wallStart = null): ?TraceSession
    {
        $this->reset();
        if (! $decision->on()) {
            return null;
        }

        $clock = new Clock($wallStart);
        $recorder = new Recorder(
            $clock,
            max(10, (int) $this->config('limits.max_spans', 5000)),
            max(2, (int) $this->config('limits.collapse_after', 20)),
        );
        $dumper = new ValueDumper($this->masker(), $this->limits(), (array) $this->config('capture', []));

        $this->session = new TraceSession((string) Str::ulid(), $kind, $decision->state, $decision->trigger, $clock, $recorder, $dumper);
        $this->session->bag['tentative'] = $decision->hints;
        $this->on = true;
        Hot::$on = true;

        return $this->session;
    }

    /** Start the request trace; returns false when this request is not recorded. */
    public function beginRequest(Request $request): bool
    {
        $decision = $this->decider()->decide($request);
        $session = $this->begin('request', $decision, self::bootTime());
        if ($session === null) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        $session->meta['method'] = $request->getMethod();
        $session->meta['path'] = $path;
        $ip = (string) $request->ip();
        $session->meta['ip_hash'] = $ip === '' ? null : substr(hash_hmac('sha256', $ip, (string) App::config()->get('app.key', 'request-tracer')), 0, 16);

        $kernel = App::container()->make(Kernel::class);
        if (method_exists($kernel, 'getGlobalMiddleware')) {
            App::container()->make(Proxy\ProxyRegistry::class)->markMiddleware($kernel->getGlobalMiddleware());
        }

        $root = $session->recorder->begin(SpanType::Request, $request->getMethod().' '.$path);
        $root->startUs = 0;
        $root->input = $session->dump($request);
        $session->root = $root;

        return true;
    }

    /** End the request span. The trace is stored later, in finish() (called from terminate()). */
    public function endRequest(Request $request, ?Response $response): void
    {
        $session = $this->session;
        if ($session === null || $session->root === null) {
            return;
        }

        if ($response !== null) {
            $session->meta['status'] = $response->getStatusCode();
            $session->root->output = $session->dump($response);
            $session->root->meta['summary'] = Summary::of($response);
            $exception = property_exists($response, 'exception') ? $response->exception : null;
            if ($exception instanceof \Throwable) {
                $session->exceptions()->markUncaught($exception, $this->ignorable($exception));
            }
        }
        $route = $request->route();
        if (is_object($route) && method_exists($route, 'getName')) {
            $session->meta['route_name'] = $route->getName();
        }
        $session->recorder->end($session->root);
    }

    /**
     * Start the trace of a queued job, an artisan command or a scheduled task. Returns null when it is not recorded,
     * or when another trace is already running (a `sync` job inside a request, `Artisan::call()` inside a command):
     * then the work simply becomes part of the outer trace.
     *
     * @param  array<string, mixed>  $input  what the root step shows as its input (masked like every other value)
     */
    public function beginBackground(string $kind, string $name, SpanType $type, string $label, Decision $decision, array $input = [], ?string $parentTraceId = null): ?TraceSession
    {
        if ($this->session !== null || ! $decision->on()) {
            return null;
        }

        $session = $this->begin($kind, $decision);
        if ($session === null) {
            return null;
        }

        $session->meta['path'] = $name;
        $session->parentTraceId = $parentTraceId;
        $root = $session->recorder->begin($type, $label);
        $root->startUs = 0;
        $root->input = $session->dump($input);
        $session->root = $root;

        return $session;
    }

    /**
     * End and store a trace started with beginBackground(). Safe to call twice and when nothing is being recorded.
     *
     * @param  string  $kind  only a running trace of this kind is ended: a job finishing inside a request or a command (`sync` queue) is not its end
     * @param  string|null  $id  only finish when this is the running trace (a shutdown hook must not end a later one)
     */
    public function endBackground(string $kind, ?int $status, ?string $summary, ?\Throwable $failure = null, ?string $id = null): void
    {
        $session = $this->session;
        if ($session === null || $session->root === null || $session->kind !== $kind || ($id !== null && $session->id !== $id)) {
            return;
        }

        try {
            $session->meta['status'] = $status;
            $session->root->meta['summary'] = $summary;
            if ($failure !== null) {
                $session->exceptions()->markUncaught($failure, $this->ignorable($failure));
                $session->root->flag = SpanFlag::Failed;
            }
            $session->recorder->end($session->root);
        } catch (\Throwable $e) {
            Failure::report($e, 'ending background trace');
        }

        $this->finish();
    }

    /**
     * Build and persist the trace, then reset. Safe to call when nothing is being recorded.
     * This is the only place trace data is written, and it runs in terminate().
     */
    public function finish(): void
    {
        $session = $this->session;
        if ($session === null) {
            return;
        }

        // Stop recording first: nothing the tracer does below may become part of the trace.
        $this->on = false;
        Hot::$on = false;

        try {
            if ($session->state === RecordingState::On && ! $session->discard) {
                $data = (new TraceBuilder(App::application(), $this))->build($session);
                $this->persist($data);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'finishing trace');
        } finally {
            $this->reset();
        }
    }

    /** Drop everything recorded for the current trace. */
    public function reset(): void
    {
        $this->on = false;
        Hot::$on = false;
        $this->session = null;
    }

    /** Called by collectors that decide late (watch list, sampling, a token bound to a user) that a tentative trace is kept. */
    public function confirm(string $trigger): void
    {
        if ($this->session !== null && $this->session->state === RecordingState::Tentative) {
            $this->session->state = RecordingState::On;
            $this->session->trigger = $trigger;
        }
    }

    /** @return array{tenant: mixed, module: mixed, user: mixed} */
    public function resolveContext(?Request $request): array
    {
        $out = ['tenant' => null, 'module' => null, 'user' => null];
        foreach (['tenant', 'module', 'user'] as $key) {
            $callback = $this->resolvers[$key] ?? null;
            if ($callback === null && $key !== 'user') {
                $class = $this->config('resolvers.'.$key);
                if (is_string($class) && $class !== '' && class_exists($class)) {
                    $callback = Closure::fromCallable(App::container()->make($class));
                }
            }
            if ($callback !== null) {
                $out[$key] = Failure::guard(static fn () => $callback($request), 'resolver '.$key);
            }
        }

        return $out;
    }

    /** Tell the dashboard something about this trace (e.g. "not traceable: final"). No-op when not recording. */
    public function note(string $kind, string $target, string $message): void
    {
        $this->session?->note($kind, $target, $message);
    }

    // ---------------------------------------------------------------------------------------------
    // trace() helper
    // ---------------------------------------------------------------------------------------------

    /**
     * Backs the global trace() helper: returns $value unchanged and, when recording, stores it as a span.
     *
     * @template T
     *
     * @param  T  $value
     * @return T
     */
    public static function helper(mixed $value, ?string $label = null): mixed
    {
        if (! Hot::$on) {
            return $value;
        }
        $tracer = self::$instance;
        if ($tracer === null || ! $tracer->on) {
            return $value;
        }

        try {
            $session = $tracer->session;
            if ($session !== null) {
                [$file, $line] = CallSite::find();
                $span = $session->recorder->leaf(SpanType::Service, $label ?? 'trace()', 0, $file, $line);
                if (! $span->ghost) {
                    $span->meta['helper'] = true;
                    $span->output = $session->dump($value);
                }
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'trace() helper');
        }

        return $value;
    }

    /** Exceptions that are normal control flow (validation, 404, auth...): no problem is raised for them. */
    private function ignorable(\Throwable $e): bool
    {
        foreach ((array) $this->config('problems.ignore_exceptions', []) as $class) {
            if (is_string($class) && $e instanceof $class) {
                return $e instanceof HttpExceptionInterface ? $e->getStatusCode() < 500 : true;
            }
        }

        return false;
    }

    private function persist(TraceData $data): void
    {
        $queue = (array) $this->config('storage.queue', []);
        if (($queue['enabled'] ?? false) === true) {
            Jobs\PersistTrace::dispatchFor($data, $queue);

            return;
        }

        $this->storage()->store($data);
    }

    private static function bootTime(): ?float
    {
        if (defined('LARAVEL_START')) {
            return (float) LARAVEL_START;
        }

        $t = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;

        return is_float($t) ? $t : null;
    }
}
