<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Collectors;

use Ahmedhsieb\RequestTracer\Recording\BackgroundDecider;
use Ahmedhsieb\RequestTracer\SpanType;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\CallSite;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Support\Locator;
use Ahmedhsieb\RequestTracer\Support\Summary;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Queue;

/**
 * Jobs dispatched by the request (`job` spans), and the link from a job to the trace that dispatched it: while recording,
 * every queue payload gets `request_tracer: {trace, span}` so the worker can set `parent_trace_id` on the job's own trace.
 */
final class JobCollector implements Collector
{
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

        $this->registerWorkerListeners($events, $tracer);

        // A static closure: it reads the *current* tracer, so nothing leaks between requests. Registered on every boot (once per process
        // in production and under Octane); the framework's test teardown clears these callbacks, so a "registered once" flag would lose it.
        Queue::createPayloadUsing(static function (): array {
            $tracer = Tracer::$instance;
            $session = $tracer?->on ? $tracer->session() : null;
            if ($session === null) {
                return [];
            }

            return ['request_tracer' => ['trace' => $session->id, 'span' => $session->recorder->currentId()]];
        });
    }

    /**
     * The worker side: every processed job is its own trace (kind `job`), linked to the trace that dispatched it through the
     * `request_tracer` stamp in the payload. The trace is stored when the job finishes (`JobProcessed`), throws (`JobExceptionOccurred`)
     * or is marked failed (`JobFailed`, which also covers timeouts and "attempted too many times").
     */
    private function registerWorkerListeners(Dispatcher $events, Tracer $tracer): void
    {
        $events->listen(JobProcessing::class, function (JobProcessing $event) use ($tracer): void {
            Failure::guard(function () use ($event, $tracer): void {
                if ($tracer->session() !== null) {
                    return;                                         // `sync` queue: the job is part of the request that runs it
                }
                $name = $event->job->resolveName();
                $stamp = $event->job->payload()['request_tracer'] ?? null;
                $parent = is_array($stamp) && is_string($stamp['trace'] ?? null) ? $stamp['trace'] : null;

                $decision = App::container()->make(BackgroundDecider::class)->decide('job', $name, $parent !== null);
                $tracer->beginBackground('job', $name, SpanType::Job, Locator::shortClass($name), $decision, [
                    'job' => $name,
                    'connection' => $event->connectionName,
                    'queue' => $event->job->getQueue(),
                    'attempts' => $event->job->attempts(),
                    'id' => $event->job->getJobId(),
                ], $parent);
            }, 'job processing');
        });

        $events->listen(JobProcessed::class, function () use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('job', null, 'Processed'), 'job processed');
            }
        });

        $events->listen(JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('job', null, 'Threw '.Summary::of($event->exception), $event->exception), 'job exception');
            }
        });

        $events->listen(JobFailed::class, function (JobFailed $event) use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('job', null, 'Threw '.Summary::of($event->exception), $event->exception), 'job failed');
            }
        });
    }
}
