# 4. Configuration reference

You do not need a config file. Every key below has a default, and a published file only has to contain the keys you change. Publish the full file with `php artisan vendor:publish --tag=request-tracer-config`; it lives at `config/request-tracer.php`.

How your file and the defaults combine:

- **Sections merge key by key**, down to the last level: `'triggers' => ['sampling' => ['rate' => 0.2]]` changes the rate and keeps every other default.
- **Lists that say what to look at replace the default**: `'code' => ['paths' => ['modules/Billing']]` means "only that folder".
- **Lists of things to leave out or to mask add to the defaults**: `exclude.paths`, `exclude.routes`, `exclude.commands`, `exclude.jobs`, `masking.keys`, `masking.value_patterns`, `capture.ignore_events`, `capture.request_headers`, `capture.response_headers` and `problems.ignore_exceptions`. `'exclude' => ['paths' => ['admin/*']]` keeps `login`, the webhooks and the health checks excluded, and `'masking' => ['keys' => ['ssn']]` keeps `password` and `token` masked. To take a default away, write it with a `!`: `'paths' => ['!login']` traces the login page too.

Rules that apply everywhere:

- Patterns (`fnmatch`) use `*` as a wildcard and are case-sensitive. Path patterns have no leading slash (`api/*`).
- Values that name behaviour are **class names, not closures**, so `php artisan config:cache` keeps working. Closures can still be registered from a service provider (see [Extending](11-extending.md)).
- Environment variables are read only inside this file (`REQUEST_TRACER_ENABLED`, `REQUEST_TRACER_MODE`, `REQUEST_TRACER_DB_CONNECTION`).

## Top level

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` (`REQUEST_TRACER_ENABLED`) | Master switch. When `false` the package registers **no** middleware, listener, autoloader, container hook or dashboard route: one boolean is read and nothing else happens. The `request-tracer:*` commands stay available. |
| `mode` | `null` (`REQUEST_TRACER_MODE`) | `null` or `triggered` (the default **everywhere**, local included): things are recorded only **while `php artisan request-tracer:listen` is running**, or for a request that carries a signed header, belongs to a watched user, or was sampled. `always` records every request that is not excluded, with no command needed. `off` never records (the dashboard still shows what is stored). |
| `local_environments` | `['local']` | Environments that count as local: the dashboard is open without a gate there. |
| `log_channel` | `'request-tracer'` | Where the tracer's own failures are logged. A `single` channel writing `storage/logs/request-tracer.log` is created when your logging config has none with this name. |

## `code`

Which of your own code is looked into. **Everything inside these folders and files is traced** while the tracer records: every call with its input and output, and line by line every condition, block and variable. You never list classes one by one.

| Key | Default | What it does |
|---|---|---|
| `paths` | `['app']` | Folders or files, relative to the project (or absolute): `'app'`, `'modules/Billing/src'`, `'lib/Money.php'`. Vendor code is never looked into, whatever the paths say. An empty list traces nothing by folder. |
| `except` | `[]` | What to leave out. A folder (`'app/Legacy'`), a file (`'app/Support/Money.php'`) or classes (`'App\Reports\*'`, `'App\Services\Heavy*'`: an entry with a backslash and no slash is a class pattern). `#[NoTrace]` on a class does the same from inside the code. |
| `line_by_line` | `true` | `false` keeps the steps with input and output but turns the per-line view off (cheaper). |

Two details. Line-by-line tracing compiles a copy of a file the first time it is loaded **while somebody listens** (or the process was started with `REQUEST_TRACER=1`), so a request that nobody traces runs your original files. And container proxies (the steps with input and output) are not given to what the framework builds or calls itself: models, controllers (they have a step of their own), console commands, service providers, form requests, queued jobs, mailables, notifications, exceptions and the kernels. Those files are still traced line by line.

`proxies.namespaces`, `deep_trace.namespaces` and `deep_trace.files` (below) still work, as extras that are traced even when they are outside `code.paths`.

## `replay`

Running a request that already happened again, traced (dashboard: *Replay a request*; terminal: `request-tracer:replay`). See [Using the dashboard](09-using-the-dashboard.md#replay-a-request-that-already-happened).

| Key | Default | What it does |
|---|---|---|
| `enabled` | `null` | `null` allows replay everywhere except the `production` environment; `true` allows it everywhere; `false` never. Database changes of a replay are rolled back and e-mails and queued jobs go nowhere, but calls to other services are real: that is why production is off by default. |
| `timeout` | `120` | Seconds a replay may run. |

## `scope` and `exclude`

| Key | Default | What it does |
|---|---|---|
| `scope.paths` | `[]` | When not empty, only requests whose **URL path** matches one of these patterns can be recorded (`api/*`). Not to be confused with `code.paths`, which are folders of your code. |
| `exclude.paths` | built in: login, logout, register, password reset, two-factor, OAuth, payment webhooks, health checks, `request-tracer*`, Ignition, Horizon, Telescope, Livewire | URL **prefixes and patterns that are never recorded**, even with a valid header (`admin/*`, `api/internal/*`, `reports*`: `*` matches anything, no leading slash). **Yours are added to the built-in ones**; `'!login'` takes one away. The dashboard's own path is always excluded. A replay ignores this list. |
| `exclude.routes` | `[]` | Route **names** (`fnmatch`, e.g. `horizon.*`, `admin.*`) that are never recorded. Handy when the routes of one area share a name prefix. |
| `exclude.commands` | `queue:work`, `schedule:run`, `schedule:finish`, `serve`, `tinker`, `invoke-serialized-closure`, `migrate*`, `package:*`, `config:*`, `cache:*`, `horizon*`, `octane:*`, `request-tracer:*` and other internal commands | Artisan commands that are never recorded. |
| `exclude.jobs` | `[]` | Job class names (`fnmatch`) that are never recorded. The tracer's own `RequestTracer\Jobs\*` are always excluded. |

## `storage`

| Key | Default | What it does |
|---|---|---|
| `driver` | `'database'` | Name of the storage driver. `database` is built in; others come from `drivers`. |
| `connection` | `null` (`REQUEST_TRACER_DB_CONNECTION`) | Database connection that holds the tables. `null` is the application's default connection. |
| `separate_connection` | `true` | Write on a dedicated PDO connection, outside the application's transactions, so a rolled-back transaction never takes the trace with it. |
| `table_prefix` | `'tracer_'` | Prefix of the five tables. Must match the published migration. |
| `compress_over_bytes` | `1024` | Payloads larger than this are stored gzip-compressed. |
| `queue.enabled` | `false` | Store traces from a queued job instead of in `terminate()`. Needs a running queue worker. |
| `queue.connection`, `queue.queue` | `null` | Connection and queue name for that job. |
| `insert_chunk` | `500` | Rows per `INSERT` when a trace is written. |
| `drivers` | `[]` | `'name' => ClassName::class` for custom storage drivers (class must implement `RequestTracer\Storage\TraceStorage`). |

## `triggers`

| Key | Default | What it does |
|---|---|---|
| `header` | `'X-Request-Tracer'` | Request header that carries a signed token. |
| `token_ttl` | `300` | Default lifetime of a token in seconds (never more than 3600). |
| `watch_list.enabled` | `true` | Allow "record this user's requests for a while". Costs one cache read per request in `triggered` mode. Turn it off if you do not use it. |
| `watch_list.max_ttl` | `3600` | Longest a watch entry can live, in seconds. |
| `watch_list.store` | `null` | Cache store for the list (`null` = default store). |
| `sampling.enabled` | `false` | Record a share of requests in `triggered` mode. |
| `sampling.rate` | `0.01` | Share between 0 and 1. |
| `sampling.require_auth` | `true` | A sampled request is kept only if a user is authenticated; otherwise it is dropped without a write. |

## `dashboard`

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Register the dashboard and the JSON API. |
| `path` | `'request-tracer'` | URL prefix. |
| `domain` | `null` | Serve the dashboard on a dedicated domain. |
| `middleware` | `['web', Authorize::class]` | Middleware of the dashboard routes. Keep `Authorize` (it applies the gate). |
| `gate` | `'viewRequestTracer'` | Name of the gate that decides who sees the dashboard. Outside local environments nobody does until you define it. |
| `poll_seconds` | `5` | How often the request list looks for new requests (`0` = never). |
| `slow_ms` | `500` | What the "Slow" filter and the list's *slow* note consider slow. |

## `collectors`

Each key switches one built-in collector. A key mapped to a class name registers a custom collector instead (see [Extending](11-extending.md)).

| Key | Default | Records |
|---|---|---|
| `request` | `true` | The request and response, the route, the middleware pipeline and the controller. |
| `validation` | `true` | Form request and `Validator` runs: rules, input, errors. |
| `queries` | `true` | Every query with bindings, duration and the calling file and line. |
| `models` | `true` | Eloquent created / updated / deleted with before and after, and counts of retrieved rows. |
| `logs` | `true` | Log messages. |
| `exceptions` | `true` | Reported exceptions, including caught ones. |
| `http` | `true` | Requests made with the HTTP client. |
| `cache` | `true` | Cache reads, writes and forgets. |
| `events` | `true` | Dispatched events with their listeners. |
| `jobs` | `true` | Dispatched jobs and the traces of jobs a worker processes. |
| `commands` | `true` | Artisan commands and scheduled closures. |
| `transactions` | `true` | Transaction begin, commit and rollback (used for the "committed / rolled back" note on writes). |

## `capture`

| Key | Default | What it does |
|---|---|---|
| `query_source` | `true` | Find the application file and line that ran each query (uses `debug_backtrace`). |
| `query_bindings` | `true` | Store query bindings (masked by column name). |
| `ignore_events` | framework internals | Event names (`fnmatch`) that never become steps. |
| `request_headers` | an allowlist of harmless headers | Only these request headers are stored. `Authorization`, `Cookie` and everything else are dropped. |
| `response_headers` | an allowlist | Same for the response. |
| `body_max_bytes` | `4096` | Largest request body that is kept. |

## `proxies` (layer 2)

The default (`code.paths`) already gives every class in your project a step. These keys add to that.

| Key | Default | What it does |
|---|---|---|
| `namespaces` | `[]` | Extra classes that become steps when the container resolves them, even outside `code.paths`. Patterns: `App\Services`, `App\*\Services`, `App\Domain\*`. |
| `exclude` | `[]` | Class names or namespace patterns that are never proxied (`code.except` does this for the whole feature). |
| `middleware` | `true` | A step around every middleware's `handle()`. |
| `attribute_namespaces` | `['App\\']` | Classes under these prefixes are proxied when they carry `#[Trace]`. |
| `vendor_methods` | `false` | Also trace public methods a proxied class inherits from vendor code. |
| `cache_path` | `null` | Where generated proxy classes are cached (default `storage/framework/request-tracer/proxies`). |

## `deep_trace`

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Allow line-by-line tracing at all. When `false` no autoloader is registered. |
| `namespaces` | `[]` | Extra classes (or namespaces) compiled with probes **all the time**, even when nobody listens and even outside `code.paths`. Leave it empty: `code.paths` covers your project, and only while recording. |
| `files` | `[]` | Absolute paths compiled with probes. |
| `attribute_scan` | `false` | Also read classes under `proxies.attribute_namespaces` for `#[Trace(deep: true)]` (one file read per class, so off by default). |
| `cache_path` | `null` | Where compiled copies are cached (default `storage/framework/request-tracer/compiled`). |
| `max_hits` | `20000` | Probe hits per trace; after that probes stop and the trace is marked truncated. |
| `loop_snapshots` | `5` | How many loop iterations keep the loop's variables. |
| `queue_ttl` | `3600` | How long a dashboard "Trace this file line by line" request stays queued. |

## `dumper`

| Key | Default | What it does |
|---|---|---|
| `max_depth` | `3` | Nesting depth of a stored value. |
| `max_items` | `50` | Items kept per array or object. |
| `max_string` | `2048` | Bytes kept per string. |
| `max_span_bytes` | `65536` | Largest serialised input or output of one step. |
| `memory_ceiling` | `16777216` | Bytes of captured values per trace; after that values are stored as type and size only. |

## `masking`

| Key | Default | What it does |
|---|---|---|
| `keys` | `password`, `passwd`, `secret`, `token`, `authorization`, `cookie`, `api_key`, `card`, `cvv`, `pan`, `iban` | Array keys, property names, request fields and column names that are masked. Matched as whole words, so `api_key`, `apiKey` and `api-key` are one key. An entry starting with `/` is a regular expression tested against the raw key. |
| `mask` | `'••••••••'` | What a masked value is replaced with. |
| `value_patterns` | a `Bearer …` pattern | Regular expressions run on string values; matches are replaced. |

Masking happens while a value is converted, before anything is stored. Add your own field names (`ssn`, `national_id`) to `keys`.

## `limits`

| Key | Default | What it does |
|---|---|---|
| `max_spans` | `5000` | Steps per trace. After the cap new steps are counted but not stored and the trace is marked truncated. |
| `collapse_after` | `20` | Identical siblings (same type and label) beyond this many merge into one step with a counter. |
| `max_writes` | `2000` | Table-write rows per trace. |
| `max_lines` | `20000` | Deep trace line rows per trace. |

## `problems`

| Key | Default | What it does |
|---|---|---|
| `slow_query_ms` | `100` | A query at least this slow is a problem. |
| `slow_step_ms` | `500` | A step whose own time is at least this long is a slow step. |
| `slow_step_share` | `0.4` | …or one that takes this share of the request (and at least `slow_step_min_ms`). |
| `slow_step_min_ms` | `50` | Floor for the share rule. |
| `n_plus_one_threshold` | `5` | The same query shape this many times from one place in a loop. |
| `repeated_query_threshold` | `2` | The same `SELECT` with the same bindings this many times. |
| `repeated_write_threshold` | `3` | The same write shape this many times from one place. |
| `http_failed_min_status` | `400` | An outgoing HTTP response with this status or above is a failed call. |
| `ignore_exceptions` | validation, authentication, authorization, model-not-found, token mismatch, HTTP exceptions | Uncaught exceptions that are normal control flow: no problem is raised for them (a 5xx HTTP exception still is). |

## `retention`

| Key | Default | What it does |
|---|---|---|
| `hours` | `24` | `request-tracer:prune` deletes traces older than this. |
| `max_rows` | `5000` | …and keeps at most this many, newest first (`0` = no limit). |
| `schedule` | `true` | Schedule the prune command automatically. |
| `schedule_at` | `'hourly'` | One of `everyFiveMinutes`, `everyTenMinutes`, `everyFifteenMinutes`, `everyThirtyMinutes`, `hourly`, `daily`, `twiceDaily`. |

## `resolvers`

| Key | Default | What it does |
|---|---|---|
| `tenant` | `null` | Invokable class name that receives the request (or `null`) and returns the tenant id, shown in the list and stored for filtering. |
| `module` | `null` | Same for the module (or bounded context) name. |

A resolver that throws is ignored: the column stays empty and the request is unaffected.
