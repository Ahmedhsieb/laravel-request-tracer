# 8. Large applications and ERPs

A modular monolith with hundreds of tables, dozens of modules, several databases and a few thousand requests per minute is where tracing tools usually fall over: they record too much, fill the database, and make everything slower. Request Tracer is built to be used on such an application *selectively*. This page is the playbook.

## The principle

Record **few requests, with the detail you chose**, not every request with all the detail. Everything below is a way to narrow one of those two.

## 1. Keep production in `triggered` mode

This is the default everywhere. A request costs 25 to 65 µs unless somebody asked for it ([benchmarks](benchmarks.md)). Ask for everything with `request-tracer:listen`, for one request with a signed header, for one user with the watch list, or for a small share with sampling. See [Running in production](07-running-in-production.md).

## 2. Say which module and tenant a trace belongs to

With many modules and tenants, "find the request from tenant 17's purchasing module" has to be a filter, not a search. Two invokable classes fill the columns:

```php
// app/Tracing/ResolveTenant.php
final class ResolveTenant
{
    public function __invoke(?Request $request): ?string
    {
        return tenant()?->id;                          // whatever your multi-tenancy package offers
    }
}

// app/Tracing/ResolveModule.php
final class ResolveModule
{
    public function __invoke(?Request $request): ?string
    {
        return $request?->route()?->getAction('module') ?? $request?->segment(1);
    }
}
```

```php
// config/request-tracer.php
'resolvers' => [
    'tenant' => App\Tracing\ResolveTenant::class,
    'module' => App\Tracing\ResolveModule::class,
],
```

The module appears in the dashboard's module filter and next to each request; both columns are indexed. A resolver that throws is ignored and the request is unaffected. If you prefer closures, register them in a service provider: `Tracer::resolveTenantUsing(fn ($request) => ...)`.

## 3. Point it at the code you are looking at

By default the whole `app/` folder is traced. On a large codebase, **narrow it to the folders involved** while you investigate, and leave the heavy or noisy parts out:

```php
'code' => [
    'paths'  => ['app/Modules/Purchasing', 'app/Modules/Inventory', 'app/Support/Money.php'],
    'except' => ['app/Modules/Purchasing/Legacy', 'App\Modules\*\Services\Cached*'],
],
```

Folders and single files both work, nothing is listed class by class, and `except` takes folders, files and class patterns (`*` stands for one namespace segment inside a pattern, and anything below at the end). Widen the list when you need to, not before. Every class in the folders gets a step with input and output and is compiled for line by line; both happen only while somebody listens, so the cost is paid by the people looking at a trace, not by your users.

Do not trace code that runs thousands of times per request (a money formatter, a cache key builder): put it in `except`, or `#[NoTrace]` on the class. The Deep trace cap (`deep_trace.max_hits`) keeps a hot loop to one row per probe, but the probes still run.

## 4. Let the limits protect you

A report that runs 40 000 queries must not produce a 40 000-step trace.

| Limit | Default | Effect |
|---|---|---|
| `limits.collapse_after` | `20` | Identical siblings (same kind and label) beyond this many merge into **one step with a counter** (`×40 000`). The N+1 detector still sees every query. |
| `limits.max_spans` | `5000` | After this many steps new ones are counted but not stored, and the trace says it is truncated. |
| `limits.max_writes` | `2000` | Table-write rows per trace. |
| `deep_trace.max_hits` | `20000` | Probe hits per trace; a hot loop costs one row, not one row per iteration. |
| `dumper.*` | see [configuration](04-configuration.md#dumper) | Depth, items, string length and a memory ceiling per trace. |

## 5. Put the traces where they hurt nobody

- **Own connection:** `REQUEST_TRACER_DB_CONNECTION=tracer` keeps the five tables out of your main database, its backups and its replication. Traces are also written on a separate PDO, so they never take part in your transactions.
- **Queue the write:** with `storage.queue.enabled = true` the PHP worker serialises the trace and moves on; a queue worker stores it.
- **Retention:** `retention.hours` and `retention.max_rows` bound the table size; pruning is scheduled hourly. A trace is a few KB to a few hundred KB, so 5 000 rows is modest; raise it deliberately.

## 6. Several databases

Table writes are attributed to the connection that received them: the writes card shows `orders (connection: erp)` and `audit_log (connection: audit)` separately, and each write says whether its own transaction committed or rolled back. Queries show their connection on the step.

## 7. Queues, commands and the scheduler

Jobs are linked to the request that dispatched them, and commands can be recorded on demand with `REQUEST_TRACER=1`. See [Queued jobs, commands and scheduled tasks](10-queues-commands-scheduled-tasks.md).

## 8. A rollout that works

1. Install on staging and run `php artisan request-tracer:listen`, with one module's folder in `code.paths`.
2. Define the gate for the people who debug, and the two resolvers.
3. Go to production with the defaults, the gate and the resolvers. Check that `php artisan request-tracer:listen` on the server makes requests appear.
4. When an incident needs it: narrow `code.paths` to the one or two folders involved, run `request-tracer:listen` on the server (or replay the failing request on staging with the cURL you have), read the trace, stop listening.

## What it will not do for you

- It does not aggregate across requests (percentiles, error rates). Use your APM for fleet-wide numbers and Request Tracer to explain **one** request.
- A secret under an innocent field name is stored as is. Review `masking.keys` for your domain (`ssn`, `national_id`, `bank_account`), and keep the gate narrow.
