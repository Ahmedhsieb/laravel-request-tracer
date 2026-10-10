<?php

declare(strict_types=1);

namespace RequestTracer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (prepended, so it is the outermost layer). handle() starts the request trace and ends its root span;
 * terminate() — which Laravel runs after the response was sent — builds and stores the trace.
 *
 * It holds no state: the Tracer singleton does, because Laravel creates a new instance of terminable middleware.
 */
final class RecordRequest
{
    public function __construct(private readonly Tracer $tracer) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $started = false;
        if ($this->tracer->enabled()) {
            $started = Failure::guard(fn (): bool => $this->tracer->beginRequest($request), 'starting request trace', false);
        }

        $response = null;
        try {
            return $response = $next($request);
        } finally {
            if ($started) {
                Failure::guard(fn () => $this->tracer->endRequest($request, $response instanceof Response ? $response : null), 'ending request trace');
            }
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        Failure::guard(fn () => $this->tracer->finish(), 'storing trace');
    }
}
