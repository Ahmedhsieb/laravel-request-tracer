<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
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
     * When things are recorded:
     *   null / 'triggered' -> only while `php artisan request-tracer:listen` is running (like `queue:work`), or for a request
     *                         that carries a signed header, a watched user, or a sampled request. The default, everywhere.
     *   'always'           -> every request that is not excluded, with no command needed
     *   'off'              -> never record (the dashboard still shows what is stored)
     */
    'mode' => env('REQUEST_TRACER_MODE'),

    /*
     * Store everything, whatever its size, so you never have to tune the numbers below (`dumper.*`, `limits.*`, `deep_trace.max_hits`).
     * The only thing that still stops the capture is the process running out of memory: once it has used 70% of PHP's memory_limit the
     * tracer stops storing and says so on the trace, instead of breaking the request. Bigger traces cost more database space and more
     * time after the response. For debugging, not for production.
     */
    'unlimited' => env('REQUEST_TRACER_UNLIMITED', false),

    /*
     * Environments that count as "local": the dashboard is open without a gate there.
     */
    'local_environments' => ['local'],

    /*
     * The code that is looked into. Everything inside these folders and files is traced while the tracer records: every call
     * (input and output) and, line by line, every condition, block and variable. Nothing has to be listed class by class.
     */
    'code' => [
        'paths' => ['app'],         // folders or files, relative to the project (or absolute): 'app', 'modules/Billing/src', 'app/Support/Money.php'
        'except' => [],             // folders, files or classes to leave out: 'app/Legacy', 'app/Support/Money.php', 'App\Legacy\*'
        'line_by_line' => true,     // false = calls with input and output only, no per-line view
    ],

    /*
     * Replaying a request that already happened ("run it again, traced", from the dashboard or `request-tracer:replay`).
     * The person who replays chooses how: a simulation changes nothing (database changes are rolled back; e-mails, jobs, notifications
     * and calls made with Laravel's HTTP client are not sent), a real run does what the request does and records what it can undo.
     *   enabled: null -> allowed everywhere except the production environment, true -> allowed everywhere, false -> never
     *   real:    the same for running for real; in production it needs `real => true` as well as `enabled => true`
     */
    'replay' => [
        'enabled' => null,
        'real' => null,
        'undo_hours' => 24,         // how long what a real run changed can still be undone
        'undo_path' => null,        // where the (encrypted) undo logs are kept; default storage/framework/request-tracer/undo
        'timeout' => 120,           // seconds
        'memory_limit' => '2G',     // of the process that runs the request: it traces every line, so it needs several times a normal request's memory; '' = php.ini's
    ],

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
        'insert_max_bytes' => 3145728,                          // an INSERT is split after this many bytes of values (keep it below the database's max_allowed_packet)
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
        'middleware' => null,                                   // null = the light web stack (cookies, session, CSRF) + the gate; ['web', Authorize::class] = the application's whole web group
        'gate' => 'viewRequestTracer',
        'poll_seconds' => 5,                                    // the request list refreshes this often (0 = never)
        'slow_ms' => 500,                                       // what the "Slow" filter chip considers slow
        'slow_step_ms' => 100,                                  // what the "Slow" / "Fast" filter of the steps of a request considers slow
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
     * The request journal: one light row per request, so a request nobody traced can still be found ("what hit us at 14:03?")
     * and replayed with a full trace (dashboard: Journal, then Replay). Off by default. It costs one INSERT per request, after the
     * response was sent. Secrets are masked before anything is stored, which means a replay of such a request carries the mask.
     */
    'journal' => [
        'enabled' => env('REQUEST_TRACER_JOURNAL', false),
        'hours' => 1,                                           // entries older than this are dropped
        'max_rows' => 500,                                      // and so is everything beyond the newest this many
        'body_max_bytes' => 16384,                              // a larger body is not kept (the entry says so)
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
