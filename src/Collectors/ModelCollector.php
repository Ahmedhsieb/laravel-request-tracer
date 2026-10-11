<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Locator;
use RequestTracer\Tracer;
use RequestTracer\TraceSession;

/**
 * Eloquent created / updated / deleted events: a `model` span with the row before and after (`getOriginal()` / `getChanges()`),
 * and the write bookkeeping. `retrieved` only counts rows per table (no span: a list page can hydrate thousands of models).
 */
final class ModelCollector implements Collector
{
    private const WRITE_EVENTS = ['created' => true, 'updated' => true, 'deleted' => true, 'forceDeleted' => true];

    public static function key(): string
    {
        return 'models';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen('eloquent.*', function (string $eventName, array $payload) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            try {
                $this->handle($tracer, $eventName, $payload);
            } catch (\Throwable $e) {
                Failure::report($e, 'model collector');
            }
        });
    }

    /** @param  array<int, mixed>  $payload */
    private function handle(Tracer $tracer, string $eventName, array $payload): void
    {
        // "eloquent.created: App\Models\Order"
        $colon = strpos($eventName, ':');
        if ($colon === false) {
            return;
        }
        $kind = substr($eventName, 9, $colon - 9);
        $model = $payload[0] ?? null;
        if (! $model instanceof Model) {
            return;
        }
        $session = $tracer->session();
        if ($session === null) {
            return;
        }

        if ($kind === 'retrieved') {
            $table = $model->getTable();
            $session->tablesRead[$table] ??= ['queries' => 0, 'rows' => 0];
            $session->tablesRead[$table]['rows']++;

            return;
        }
        if (! isset(self::WRITE_EVENTS[$kind])) {
            return;
        }

        $tracker = $session->writeTracker();
        $tracker->fromModel($kind, $model);
        $write = $session->writes[count($session->writes) - 1] ?? null;

        [$file, $line] = CallSite::find();
        if ($file === null) {
            [$file, $line] = Locator::classFile($model::class);
        }
        $key = $model->getKey();
        $verb = $kind === 'forceDeleted' ? 'force deleted' : $kind;
        $label = Locator::shortClass($model::class).(is_scalar($key) ? ' #'.$key : '').' '.$verb;

        $span = $session->recorder->leaf(SpanType::Model, $label, 0, $file, $line);
        if ($span->ghost) {
            return;
        }
        $span->connection = (string) ($model->getConnectionName() ?? $write['connection'] ?? '');
        $span->meta['table'] = $model->getTable();
        $span->meta['event'] = $kind;
        $span->meta['summary'] = ucfirst($verb).' in '.$model->getTable();
        if ($write !== null) {
            $span->input = $write['before'] === null ? null : $this->asValue($session, (string) $write['before']);
            $span->output = $write['after'] === null ? null : $this->asValue($session, (string) $write['after']);
        }
    }

    /** The write bookkeeping already holds masked plain JSON; re-dump it through the dumper so the dashboard renders it like any value. */
    private function asValue(TraceSession $session, string $json): string
    {
        $decoded = json_decode($json, true);

        return $session->dump(is_array($decoded) ? $decoded : $json);
    }
}
