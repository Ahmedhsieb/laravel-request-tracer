# How capture works

> Status: written phase by phase. This page currently covers the core (spans, recorder, value format, masking) layers 1–4 (framework collectors, container proxies, attributes, `trace()`). Deep trace is added by its phase.

A trace is a **tree of spans**. Every collector produces spans into one `Recorder`; at `terminate()` the finished tree
is written to storage. Nothing is written while the request runs.

## Spans

| Field | Meaning |
|---|---|
| `id`, `parent_id` | per-trace integers; the tree is rebuilt from them |
| `type` | `request`, `command`, `middleware`, `controller`, `validation`, `service`, `query`, `model`, `http`, `event`, `job`, `exception`, `cache`, `log` |
| `label` | what the dashboard shows (`GET /api/orders`, `OrderService::create`, the SQL text...) |
| `file`, `line` | where it happened in *your* code (relative to the application base path) |
| `start_us`, `duration_us` | microseconds since the start of the request. Time spent by the tracer itself (dumping values) is subtracted. |
| `memory_delta` | bytes of PHP memory allocated while the span was open |
| `input`, `output` | the captured values as JSON (see below), loaded lazily by the dashboard |
| `flag` | `slow`, `repeated`, `caught`, `failed` or none |
| `connection` | database connection for queries |
| `count` | `> 1` when identical spans were collapsed into this one |

## Caps and collapsing (large applications)

* `limits.max_spans` (default 5 000): after this many stored spans new spans become *ghosts* – they are timed and counted but not stored, and everything that happens inside a ghost is swallowed. The trace is marked `truncated` and gets a `truncated` problem.
* `limits.collapse_after` (default 20): the 21st identical sibling (same parent, type and label) and all later ones merge into one aggregate span with `count` and the *total* time, flagged `repeated`.
* Counters (queries, writes, problems) are independent of spans, so detection stays correct when spans are collapsed.

## The value format (`input` / `output`)

Values are converted with Symfony's `VarCloner` and a custom dumper into a compact JSON tree. Plain JSON scalars stand for themselves; everything else is a tagged node (`"$"` is the tag):

```jsonc
"text"  42  1.5  true  null                         // scalars
{"$":"str","v":"first 2 KB…","cut":3912}            // truncated string
{"$":"arr","n":3,"list":true,"i":[1,2,3]}           // list;  "n" is the real size
{"$":"arr","n":2,"i":[["a",1],["b",2]],"cut":40}    // map; "cut" = items not stored
{"$":"obj","c":"App\\Models\\Order","kind":"model","i":[["attributes","virtual",{…}]]}
{"$":"mask"}                                        // a secret; the real value was never stored
{"$":"type","t":"Closure"}                          // closures, resources and PDO: type name only
{"$":"bin","len":2048}  {"$":"float","v":"INF"}  {"$":"int","v":"9223372036854775807"}
```

Limits (all in `config/request-tracer.php` → `dumper`): depth 3, 50 items per array/collection, 2 KB per string, 64 KB per value, 16 MB per trace. A value over its limit is shortened and marked (`cut`); a value over 64 KB is re-dumped at lower depth and finally reduced to a type marker; after the per-trace ceiling every value is stored as type and size only.

Eloquent models are stored as class, key, `attributes` (raw, no accessors – so dumping never runs queries) and the *names* of loaded relations. Collections as `count` plus the first items. `$hidden` attributes are masked.

## Masking

Masking happens **inside the dumper, while the value is converted**; the raw secret is never written to a structure, a JSON string or a database column.

* A key is sensitive when it matches an entry of `masking.keys` as whole words: `api_key`, `apiKey` and `api-key` are the same key; `credit_card_number` matches `card`; `company` does **not** match `pan`. Entries of five or more characters also match inside run-together keys (`accesstoken`). Entries starting with `/` are regular expressions.
* A sensitive key replaces its whole value (even a nested array) with `{"$":"mask"}`.
* `masking.value_patterns` scrub secret-looking *values* (default: `Bearer …` tokens) inside strings.
* Request/response headers use an allowlist (`capture.request_headers`, `capture.response_headers`); everything else is dropped, not masked.
* The test-suite asserts that none of the five trace tables ever contains a masked value.

## Layer 1 – framework collectors (no setup)

Each collector can be switched off under `collectors` in the config. They only register listeners; every listener begins with one boolean check.

| Collector | Produces | Notes |
|---|---|---|
| `request` | `request` root span (input = request, output = response), `controller` span, route name, authenticated user | The controller span comes from a tiny middleware (`TraceAction`) appended to the matched route's *computed* middleware list, so it wraps exactly the action, after route and controller middleware. The route definition is never modified. |
| `validation` | `validation` span: rules + data in, passed/errors out | Installs a validator resolver on the validation factory. If your app already set its own resolver it is left alone (a debug line is logged) and you lose only this span. |
| `queries` | `query` span per statement: SQL, masked bindings, connection, calling file:line, duration | Table reads and writes are extracted from the SQL (MySQL, PostgreSQL, SQLite, SQL Server). Bindings are masked by *column name* (`where password = ?`, `insert … (email, api_token) values (?, ?)`). |
| `transactions` | `BEGIN` / `COMMIT` / `ROLLBACK` spans, and the transaction state of every write | Writes are `none` (no transaction), `committed` or `rolled_back`; a savepoint rollback only marks the writes made since that savepoint. |
| `models` | `model` span for created / updated / deleted with the row before/after (`getOriginal()` / `getChanges()`); row counts for `retrieved` | `$hidden` attributes are masked. A model event *claims* the query write it caused, so one `save()` is one write, not two. |
| `exceptions` | `exception` span for every exception that passes through the exception handler's `reportable` path, caught or not | Exceptions the handler never reports (`$dontReport`: validation, 404, auth…) are picked up from the response when they are what the response was built from. They are *not* raised as problems. |
| `http` | `http` span per outgoing call made with the HTTP client | Query string, headers and body are masked / allow-listed. Leaf spans on purpose, so `Http::pool()` works. |
| `cache` | `cache` span for hit / miss / write / forget | Values of sensitive keys (`api_token`) are masked. |
| `events` | `event` span with payload and the list of listeners | See the limitation below. |
| `jobs` | `job` span for every dispatched job; every queue payload is stamped with the dispatching trace | The worker uses the stamp to link the job trace back (phase 6). |
| `logs` | `log` span per log line with its context | |

### What layer 1 cannot see

* **Event listener duration.** Laravel runs wildcard listeners after an event's specific listeners and has no before/after hook, and replacing the `events` instance is unsafe (the router, the database connections and Eloquent hold the original). An event span is therefore an instant with the payload and the *names* of its listeners. Work done by listeners appears as the spans (queries, service calls) just before it. Listener classes inside a proxied namespace (layer 2) get their own `handle` span with a duration.
* **Exceptions that were caught and swallowed silently** (`try { … } catch (Exception $e) {}` without `report()`) are invisible to the handler. Layer 2 (a traced method that threw) and Deep trace (`catch` blocks) record them.
* **Row counts of reads.** `QueryExecuted` carries no result size. Eloquent-hydrated rows are counted through `retrieved` events; everything else shows the number of queries.
* **Bindings of `?` operators** in PostgreSQL JSON queries are escaped as `??` by Laravel; they are counted correctly, column names for them are unknown (`null`) and only value patterns mask them.

## Problems

Detected once per trace, after the response, from counters that do not depend on how many spans were stored:

| Kind | Rule (config `problems.*`) |
|---|---|
| `exception_caught` / `exception_uncaught` | every recorded exception; uncaught normal-control-flow exceptions (422, 404, 401, 403, 419, other 4xx) are ignored |
| `n_plus_one` | the same SELECT shape from the same file:line ran ≥ `n_plus_one_threshold` (5) times with that many *different* bindings |
| `repeated_query` | an identical SELECT (same SQL and bindings) ran ≥ `repeated_query_threshold` (2) times; or the same INSERT/UPDATE/DELETE shape ran ≥ `repeated_write_threshold` (3) times from one place |
| `slow_query` | a query took ≥ `slow_query_ms` (100) |
| `slow_step` | a service / controller / middleware / http / job / validation span spent ≥ `slow_step_ms` (500) *by itself* (its own time, children excluded), or ≥ `slow_step_share` (40 %) of the request and ≥ `slow_step_min_ms` (50) |
| `http_failed` | the call could not connect or returned ≥ `http_failed_min_status` (400) |
| `truncated` | the span cap or another recording cap was reached |

## Layer 2 – container proxies

List the namespaces you care about in `proxies.namespaces`:

```php
'proxies' => ['namespaces' => ['App\Services', 'App\Domain\*\Actions']],
```

Every class in those namespaces that the container resolves *while a trace is recorded* is replaced by a generated subclass that overrides every **public, non-static, non-final** method. An override costs one boolean check when nothing is recorded; when recording it opens a `service` span with the arguments (by parameter name, so `$password` is masked), calls `parent::method()`, and closes the span with the return value or the exception — which is re-thrown unchanged.

* The subclass is built **through the container** (`$container->build(ProxyClass)`), so the parent constructor receives its dependencies. Singletons stay singletons, contextual bindings (`when(Foo::class)->needs(...)`) are copied to the proxy, and an interface bound to a class proxies the concrete class.
* Signatures are produced from reflection: by-reference and variadic parameters, nullable / union / intersection types, `self` / `static` / `void` / `never` returns, constant, array and enum defaults, and parameter / method / class attributes (so Laravel's `#[Middleware]`-style attributes still work).
* Generated files live in `storage/framework/request-tracer/proxies`, together with a manifest of the mtimes of the class, its parents and traits (and of the generator). Edit a file and its proxy is rewritten.
* **Middleware** gets the same treatment automatically (`proxies.middleware`): only `handle()` is overridden and the span is a `middleware` span labelled with the class name.

### Limits of proxies

Proxies hook into *container resolution*. They cannot see:

| Not traced | Why |
|---|---|
| `new Foo()` | the container is never asked |
| classes bound to a custom closure (`bind(Foo::class, fn () => new Foo(...))`) | the closure builds the object |
| private and protected methods, and calls made through `$this->helper()` inside the class | an override only sees calls that arrive from outside; use Deep trace for what happens inside |
| `static` methods | cannot be overridden on an instance |
| final classes and final methods | PHP forbids subclassing / overriding. The dashboard lists them under *Not traceable* with the reason (`App\Foo — not traceable: final class`) |
| methods returning by reference, parameters whose default is a `new` expression | cannot be reproduced exactly; listed under *Not traceable* |
| services built before the request (singletons created while the app boots) | the hook only acts while a trace is recorded |

Side effects to know about: `get_class($service)` and `static::class` return the proxy class name (`…__Proxy`), and `serialize($service)` stores that name. Put a class in `proxies.exclude` if it depends on either.

## Layer 3 – attributes

```php
use RequestTracer\Attributes\NoTrace;
use RequestTracer\Attributes\Trace;

class InvoiceCalculator
{
    #[Trace(label: 'Tax rules')]      // proxied even though App\Support is not in proxies.namespaces
    public function applyTax(Invoice $invoice): Money { /* ... */ }

    #[NoTrace]                        // never traced, even inside a proxied namespace
    public function hotPath(): int { /* ... */ }
}

#[Trace]                              // every public method of the class
class ReportBuilder { /* ... */ }
```

`#[Trace(deep: true)]` also opts the file into Deep trace (see the Deep trace guide); `#[NoTrace]` on a class or method keeps probes out of it too. Classes outside `proxies.namespaces` are only inspected for `#[Trace]` when they live under `proxies.attribute_namespaces` (default `App\`).

## Layer 4 – the `trace()` helper

```php
return trace($this->calculate($order), 'order total');   // returns the value unchanged
```

Records a span with the caller's file and line and the value in `dd()` format. When nothing is recorded it is a single boolean check and returns its argument.
