<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Locator;
use RequestTracer\Tracer;

/**
 * Jobs dispatched by the request (`job` spans), and the link from a job to the trace that dispatched it: while recording,
 * every queue payload gets `request_tracer: {trace, span}` so the worker can set `parent_trace_id` on the job's own trace.
 */
final class JobCollector implements Collector
{
    private static bool $payloadHooked = false;

    public static function key(): string
    {
        return 'jobs';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(JobQueued::class, function (JobQueued $event) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            try {
                $session = $tracer->session();
                if ($session === null) {
                    return;
                }
                $name = is_object($event->job) ? $event->job::class : (string) $event->job;
                [$file, $line] = CallSite::find();
                $span = $session->recorder->leaf(SpanType::Job, 'dispatch '.Locator::shortClass($name), 0, $file, $line);
                if ($span->ghost) {
                    return;
                }
                $span->meta['job'] = $name;
                $span->meta['summary'] = 'Dispatched to '.($event->connectionName ?: 'queue');
                $span->meta['connection'] = $event->connectionName;
                $span->meta['job_id'] = is_scalar($event->id) ? (string) $event->id : null;
                $span->input = $session->dump(['job' => is_object($event->job) ? $event->job : $name, 'connection' => $event->connectionName, 'queue' => $event->queue ?? null]);
            } catch (\Throwable $e) {
                Failure::report($e, 'job collector');
            }
        });

        // Static, registered once per process; it reads the *current* tracer, so nothing leaks between requests.
        if (! self::$payloadHooked) {
            self::$payloadHooked = true;
            Queue::createPayloadUsing(static function (): array {
                $tracer = Tracer::$instance;
                $session = $tracer?->on ? $tracer->session() : null;
                if ($session === null) {
                    return [];
                }

                return ['request_tracer' => ['trace' => $session->id, 'span' => $session->recorder->currentId()]];
            });
        }
    }
}
