<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use RequestTracer\Http\Middleware\Authorize;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/*
|--------------------------------------------------------------------------
| Request Tracer configuration
|--------------------------------------------------------------------------
|
| Every key is documented in docs/04-configuration.md. Values that are callables
| must be invokable class names (not closures) so that `php artisan config:cache`
| keeps working; closures can still be registered from a service provider with
| the Tracer facade (Tracer::resolveTenantUsing(...), ...).
|
*/

return [

    /*
     * Master switch. When false the package registers nothing that runs per request:
     * no middleware, no listeners, no autoloader, no container hook.
     */
    'enabled' => env('REQUEST_TRACER_ENABLED', true),

    /*
     * When requests are recorded:
     *   null        -> 'always' in the `local` environment, 'triggered' everywhere else
     *   'always'    -> every request that is not excluded
     *   'triggered' -> only requests with a valid signed header, a watched user, or a sampled request
     *   'off'       -> never record (dashboard still works)
     */
    'mode' => env('REQUEST_TRACER_MODE'),

    /*
     * Environments that count as "local" (recording on by default, dashboard open without a gate).
     */
    'local_environments' => ['local'],

    /*
     * Only requests whose path matches one of these patterns can be recorded. Empty = everything that is not excluded.
     */
    'scope' => [
        'paths' => [],              // fnmatch patterns on the request path, e.g. 'api/*'
    ],

    /*
     * Never record these (fnmatch patterns, without leading slash).
     */
    'exclude' => [
        'paths' => [
            'login', 'logout', 'register', 'password/*', 'forgot-password', 'reset-password*',
            'two-factor-challenge', 'sanctum/csrf-cookie', 'oauth/*',
            'webhooks/payment*', 'webhooks/stripe*', 'stripe/webhook*', 'paypal/*', 'payments/webhook*',
            'up', 'health*', 'healthz', 'ping', 'status',
            'request-tracer*',
            '_ignition/*', 'horizon*', 'telescope*', 'livewire/*',
        ],
        'routes' => [],             // route names (fnmatch)
        'commands' => [
            'queue:work', 'queue:listen', 'schedule:run', 'schedule:work', 'schedule:finish', 'schedule:interrupt', 'serve', 'tinker',
            'invoke-serialized-closure', 'request-tracer:*', 'migrate*', 'vendor:publish', 'package:*', 'config:*', 'route:*', 'view:*',
            'cache:*', 'optimize*', 'key:generate', 'storage:link', 'horizon*', 'octane:*', 'reverb:*', 'pail', 'list', 'help', 'completion', '_complete',
        ],
        'jobs' => [],               // job class names (fnmatch)
    ],

    /*
     * Storage of recorded traces.
     */
    'storage' => [
        'driver' => 'database',
        'connection' => env('REQUEST_TRACER_DB_CONNECTION'),   // null = the application's default connection
        'separate_connection' => true,                          // write on a dedicated PDO so app transactions never swallow traces
        'table_prefix' => 'tracer_',
        'compress_over_bytes' => 1024,                          // gzip payloads larger than this
        'queue' => [
            'enabled' => false,                                 // persist from a queued job instead of in terminate()
            'connection' => null,
            'queue' => null,
        ],
        'insert_chunk' => 500,
        'drivers' => [],                                        // 'name' => class-string<TraceStorage> for custom drivers
    ],

    /*
     * How recording is switched on in non-local environments.
     */
    'triggers' => [
        'header' => 'X-Request-Tracer',
        'token_ttl' => 300,                                     // seconds a signed header token stays valid (max 3600)
        'watch_list' => [
            'enabled' => true,                                  // costs one cache read per request when the tracer is enabled
            'max_ttl' => 3600,                                  // seconds a "watch this user" entry can live at most
            'store' => null,                                    // cache store for the list (null = default store)
        ],
        'sampling' => [
            'enabled' => false,
            'rate' => 0.01,                                     // 0..1
            'require_auth' => true,                             // sampled requests are only kept if a user is authenticated
        ],
    ],

    /*
     * Dashboard + JSON API.
     */
    'dashboard' => [
        'enabled' => true,
        'path' => 'request-tracer',
        'domain' => null,
        'middleware' => ['web', Authorize::class],
        'gate' => 'viewRequestTracer',
        'poll_seconds' => 5,                                    // the request list refreshes this often (0 = never)
        'slow_ms' => 500,                                       // what the "Slow" filter chip considers slow
    ],

    /*
     * Capture layer 1: framework collectors. Each one can be switched off individually.
     */
    'collectors' => [
        'request' => true,                                      // request/response, route, middleware pipeline, controller
        'validation' => true,
        'queries' => true,
        'models' => true,                                       // Eloquent created/updated/deleted + retrieved counts
        'logs' => true,
        'exceptions' => true,
        'http' => true,                                         // Http client
        'cache' => true,
        'events' => true,
        'jobs' => true,                                         // queued jobs dispatched + job traces
        'commands' => true,                                     // artisan commands + scheduled tasks
        'transactions' => true,
    ],

    'capture' => [
        'query_source' => true,                                 // find the app file:line that ran each query (debug_backtrace)
        'query_bindings' => true,
        'ignore_events' => [                                    // fnmatch on event names; noisy framework internals
            'Illuminate\Database\Events\*', 'Illuminate\Log\Events\*', 'Illuminate\Cache\Events\*',
            'Illuminate\Routing\Events\*', 'Illuminate\Http\Client\Events\*', 'Illuminate\Auth\Events\Authenticated',
            'Illuminate\Foundation\Events\*', 'Illuminate\Foundation\Http\Events\*', 'Illuminate\Queue\Events\*', 'Illuminate\Console\Events\*',
            'Illuminate\Contracts\Queue\*', 'Illuminate\Mail\Events\MessageSending', 'eloquent.*', 'bootstrapped:*',
            'bootstrapping:*', 'composing:*', 'creating:*', 'Illuminate\Events\*', 'Illuminate\Bus\Events\*',
            'Laravel\Octane\Events\*',
        ],
        'request_headers' => [                                  // allowlist, everything else is dropped
            'accept', 'accept-language', 'content-type', 'content-length', 'host', 'origin', 'referer',
            'user-agent', 'x-requested-with', 'x-forwarded-for', 'x-forwarded-proto', 'x-request-id',
        ],
        'response_headers' => [
            'content-type', 'content-length', 'cache-control', 'location', 'x-request-id', 'retry-after',
        ],
        'body_max_bytes' => 4096,
    ],

    /*
     * Capture layer 2: container proxies. List namespaces whose classes are proxied
     * when resolved from the container, e.g. ['App\\Services', 'App\\Domain\\*\\Services'].
     */
    'proxies' => [
        'namespaces' => [],                                     // patterns: App\Services, App\*\Services, App\Domain\*
        'exclude' => [],                                        // class names / namespace patterns never proxied
        'middleware' => true,                                   // a `middleware` span around every middleware's handle()
        'attribute_namespaces' => ['App\\'],                    // classes under these prefixes are proxied when they carry #[Trace]
        'vendor_methods' => false,                              // also trace public methods a proxied class inherits from vendor code
        'cache_path' => null,                                   // default storage/framework/request-tracer/proxies
    ],

    /*
     * Capture layer 3 is the #[Trace] / #[NoTrace] attributes (no configuration).
     *
     * Deep trace: line-by-line probes compiled into a copy of the file.
     * `namespaces` and `files` opt classes in; the dashboard can also queue a file for the next recorded request.
     */
    'deep_trace' => [
        'enabled' => true,
        'namespaces' => [],                                     // e.g. ['App\\Services\\OrderService', 'App\\Domain\\Billing']
        'files' => [],                                          // absolute paths
        'attribute_scan' => false,                              // read classes under proxies.attribute_namespaces for #[Trace(deep: true)] (costs a file read per class, off by default)
        'cache_path' => null,                                   // default storage/framework/request-tracer/compiled
        'max_hits' => 20000,                                    // probe hits per trace, then probes stop and the trace is marked truncated
        'loop_snapshots' => 5,                                  // iterations of a loop whose state is kept
        'queue_ttl' => 3600,                                    // seconds a dashboard "trace this file" request lives
    ],

    /*
     * Value dumper (dd() style). All limits apply per captured value.
     */
    'dumper' => [
        'max_depth' => 3,
        'max_items' => 50,
        'max_string' => 2048,                                   // bytes
        'max_span_bytes' => 65536,                              // serialized size of one input or output
        'memory_ceiling' => 16777216,                           // bytes of captured values per trace, then values become type+size only
    ],

    /*
     * Masking. Keys are matched as whole words (api_key, apiKey and api-key are the same key).
     * Entries that start with "/" are regular expressions tested against the raw key.
     */
    'masking' => [
        'keys' => ['password', 'passwd', 'secret', 'token', 'authorization', 'cookie', 'api_key', 'card', 'cvv', 'pan', 'iban'],
        'mask' => '••••••••',
        'value_patterns' => [                                   // regex run on string values; matches are replaced
            '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
        ],
    ],

    /*
     * Limits that keep very large applications manageable.
     */
    'limits' => [
        'max_spans' => 5000,
        'collapse_after' => 20,                                 // identical siblings (same type+label) beyond this merge into one span
        'max_writes' => 2000,
        'max_lines' => 20000,
    ],

    /*
     * Problem detection thresholds.
     */
    'problems' => [
        'slow_query_ms' => 100,
        'slow_step_ms' => 500,
        'slow_step_share' => 0.4,                               // or this share of the whole request (and at least slow_step_min_ms)
        'slow_step_min_ms' => 50,
        'n_plus_one_threshold' => 5,
        'repeated_query_threshold' => 2,                        // identical SELECT (same SQL + same bindings) this many times
        'repeated_write_threshold' => 3,                        // same INSERT/UPDATE/DELETE shape this many times from one place
        'http_failed_min_status' => 400,                        // outgoing HTTP responses with this status or above are "failed"
        'ignore_exceptions' => [                                // uncaught exceptions that are normal control flow (no problem is raised)
            ValidationException::class,
            AuthenticationException::class,
            AuthorizationException::class,
            ModelNotFoundException::class,
            TokenMismatchException::class,
            HttpExceptionInterface::class,
        ],
    ],

    /*
     * Retention (request-tracer:prune, scheduled automatically when enabled).
     */
    'retention' => [
        'hours' => 24,
        'max_rows' => 5000,
        'schedule' => true,
        'schedule_at' => 'hourly',
    ],

    /*
     * Resolvers for the user / tenant / module columns. Invokable class names receive the request (or null)
     * and return a scalar or null.
     */
    'resolvers' => [
        'tenant' => null,
        'module' => null,
    ],

    /*
     * Where internal failures of the tracer are logged. Created automatically when missing.
     */
    'log_channel' => 'request-tracer',
];
