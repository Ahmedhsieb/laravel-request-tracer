<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Exceptions, including the ones application code caught: everything that goes through the exception handler's `report`
 * path (report($e), unhandled exceptions, the logger) is recorded. Exceptions thrown inside traced spans and Deep trace
 * `catch` blocks reach the same tracker directly.
 *
 * Exceptions listed in the handler's `$dontReport` never reach `reportable()`; the uncaught ones among them are picked
 * up from the response (see Tracer::endRequest).
 */
final class ExceptionCollector implements Collector
{
    public static function key(): string
    {
        return 'exceptions';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        $handler = $app->make(ExceptionHandler::class);
        if (! method_exists($handler, 'reportable')) {
            return;
        }

        $handler->reportable(function (\Throwable $e) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(function () use ($tracer, $e): void {
                $tracer->session()?->exceptions()->record($e);
            }, 'exception collector');
        });
    }
}
