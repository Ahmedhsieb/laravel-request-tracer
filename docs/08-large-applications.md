# 8. Large applications and ERPs

A modular monolith with hundreds of tables, dozens of modules, several databases and a few thousand requests per minute is where tracing tools usually fall over: they record too much, fill the database, and make everything slower. Request Tracer is built to be used on such an application *selectively*. This page is the playbook.

## The principle

Record **few requests, with the detail you chose**, not every request with all the detail. Everything below is a way to narrow one of those two.

## 1. Keep production in `triggered` mode

This is the default outside `local`. A request costs about 25 µs unless somebody asked for it ([benchmarks](benchmarks.md)). Ask for a request with a signed header, for one user with the watch list, or for a small share with sampling. See [Running in production](07-running-in-production.md).

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

## 3. Opt in only the code you are looking at

Layer 2 (proxies) and Deep trace are opt-in by namespace. On a large codebase, **start narrow**:

```php
'proxies' => [
    'namespaces' => ['App\Modules\Purchasing\Services', 'App\Modules\Inventory\Services'],
    'exclude' => ['App\Modules\*\Services\Cached*'],
],
'deep_trace' => [
    'namespaces' => ['App\Modules\Purchasing\Services\PurchaseOrderService'],
],
```

Wildcards work in the middle of a pattern (`App\Modules\*\Services`). Widen the list when you need to, not before. Proxies cost one subclass per class (generated once and cached under `storage/framework/request-tracer`); Deep trace costs in proportion to the lines that run, which is why it is a list of **classes**, not a namespace like `App\`.

Do not trace code that runs thousands of times per request (a money formatter, a cache key builder): `#[NoTrace]` on the class, or `proxies.exclude`, keeps it out.

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

1. Install on staging, `mode = always`, one module's namespaces in `proxies.namespaces`.
2. Define the gate for the people who debug, and the two resolvers.
3. Go to production with the defaults (`triggered`), the gate, the resolvers and no namespaces. Check `Record a request` works.
4. When an incident needs it: add the one or two namespaces involved, send a request with a header, read the trace, remove the namespaces again.

## What it will not do for you

- It does not aggregate across requests (percentiles, error rates). Use your APM for fleet-wide numbers and Request Tracer to explain **one** request.
- A secret under an innocent field name is stored as is. Review `masking.keys` for your domain (`ssn`, `national_id`, `bank_account`), and keep the gate narrow.
