# 11. Extending

Four extension points: your own collector, your own storage, your own masking rules, and the tenant, module and user resolvers. All of them are plain classes configured by name, so `config:cache` keeps working.

## A collector of your own

A collector turns something that happens in your application into steps. Implement `RequestTracer\Collectors\Collector`, register your listeners once at boot, and start every listener with the one boolean check.

```php
namespace App\Tracing;

use App\Events\InvoiceIssued;
use Illuminate\Contracts\Foundation\Application;
use RequestTracer\Collectors\Collector;
use RequestTracer\SpanType;
use RequestTracer\Tracer;

final class BillingCollector implements Collector
{
    public static function key(): string
    {
        return 'billing';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        $app['events']->listen(InvoiceIssued::class, function (InvoiceIssued $event) use ($tracer): void {
            if (! $tracer->on) {
                return;                                   // not recording: this is the entire cost
            }

            $session = $tracer->session();
            $span = $session?->recorder->leaf(SpanType::Service, 'invoice issued');
            if ($span !== null && ! $span->ghost) {       // a ghost was merged into a sibling or dropped
                $span->input = $session->dump(['number' => $event->invoice->number]);
                $span->output = $session->dump($event->invoice);   // masked and limited like everything else
                $span->meta['summary'] = 'Invoice '.$event->invoice->number;
            }
        });
    }
}
```

Register it by name in the `collectors` section; a class name registers it, `false` would leave a built-in off:

```php
'collectors' => [
    // ... the built-in switches ...
    'billing' => App\Tracing\BillingCollector::class,
],
```

Rules of thumb:

- Never throw out of a listener. The package guards its own collectors; guard yours (`try { … } catch (\Throwable) {}` or `RequestTracer\Support\Failure::guard($callback, 'where')`, which logs to the tracer's channel).
- Use `$recorder->begin($type, $label)` and `$recorder->end($span)` for work that has a duration and children, and `$recorder->leaf(...)` for a point in time or something whose duration you already know.
- Store values with `$session->dump($value)` so masking and size limits apply. Never put a raw value into `meta`.
- Keep state in `$session->bag` (it is dropped with the trace), not in a static property: workers live for days.

Span types are the `RequestTracer\SpanType` cases: `request`, `command`, `middleware`, `controller`, `validation`, `service`, `query`, `model`, `http`, `event`, `job`, `exception`, `cache`, `log`.

## Adding a note for the dashboard

`Tracer::note('kind', 'target', 'message')` adds an entry to the "Things the tracer could not do" list of the current trace (no-op when nothing is recording). The built-in features use it for "final class: cannot be proxied" and "this file could not be compiled for Deep trace".

## Your own storage

Traces go through the `RequestTracer\Storage\TraceStorage` interface: `store`, `trace`, `traces` (filters and cursor), `spans`, `payload`, `lines`, `writes`, `problems`, `prune`, `count`, `delete`, `clear`. To keep traces somewhere else, implement it and name the class:

```php
'storage' => [
    'driver' => 'clickhouse',
    'drivers' => ['clickhouse' => App\Tracing\ClickHouseStorage::class],
],
```

The class is built by the container. `store()` receives a `TraceData` (trace row, spans, line rows, writes, problems) and is called after the response was sent, or from a queue worker when `storage.queue.enabled` is on. Look at `DatabaseStorage` for the exact shape of every row, and run `tests/Unit/StorageTest.php` against your driver as a contract test.

## Masking

Add your field names to `masking.keys`; entries starting with `/` are regular expressions tested against the raw key. Add patterns for secrets that appear *inside* strings to `masking.value_patterns`:

```php
'masking' => [
    'keys' => ['password', 'token', 'ssn', 'national_id', '/^x-api-/i'],
    'value_patterns' => ['/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', '/\b\d{4}-\d{4}-\d{4}-\d{4}\b/'],
],
```

Masking happens while a value is converted (before anything is stored), applies to array keys, object properties, request fields, query bindings (by column name) and table-write columns, and is also applied to Deep trace snippets and variable values. A name-based rule cannot know that `$x` holds a secret; name things for what they are, or `#[NoTrace]` the class.

## Resolvers

Tenant, module and user come from invokable classes (`resolvers.tenant`, `resolvers.module`) or, for closures, from a service provider:

```php
use RequestTracer\Facades\Tracer;

Tracer::resolveTenantUsing(fn (?Request $request) => $request?->header('X-Tenant'));
Tracer::resolveModuleUsing(fn (?Request $request) => $request?->segment(1));
Tracer::resolveUserUsing(fn (?Request $request) => $request?->user()?->getAuthIdentifier());   // when the default guard is not the one you use
```

A resolver that throws is ignored. Resolvers run when the trace is built, after the response was sent.

## Replacing how requests are chosen

`RequestTracer\Recording\RecordingDecider` is deliberately not final. Bind a subclass in the container and override `decide()` to implement your own policy ("only requests with this cookie", "only from the office network"):

```php
$this->app->singleton(RecordingDecider::class, App\Tracing\OfficeOnlyDecider::class);
```

Return `Decision::off()` for "no" and `new Decision(RecordingState::On, 'my-trigger')` for "yes". Jobs and commands use `BackgroundDecider`, which wraps the same class.
