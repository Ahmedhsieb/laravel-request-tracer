<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Log\Events\MessageLogged;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/** Every log line written during the request becomes a `log` span (with its context). */
final class LogCollector implements Collector
{
    public static function key(): string
    {
        return 'logs';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(MessageLogged::class, function (MessageLogged $event) use ($tracer): void {
            if (! $tracer->on || Failure::reporting()) {
                return;
            }
            try {
                $session = $tracer->session();
                if ($session === null) {
                    return;
                }
                [$file, $line] = CallSite::find();
                $message = is_string($event->message) ? $event->message : (string) json_encode($event->message);
                $span = $session->recorder->leaf(SpanType::Log, '['.strtoupper($event->level).'] '.$message, 0, $file, $line);
                if ($span->ghost) {
                    return;
                }
                $span->meta['level'] = $event->level;
                $span->input = $session->dump(['message' => $message, 'context' => $event->context]);
            } catch (\Throwable $e) {
                Failure::report($e, 'log collector');
            }
        });
    }
}
