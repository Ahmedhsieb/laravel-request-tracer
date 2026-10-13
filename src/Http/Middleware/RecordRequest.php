<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Middleware;

use Ahmedhsieb\RequestTracer\Journal\Journal;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Tracer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (prepended, so it is the outermost layer). handle() starts the request trace and ends its root span;
 * terminate() — which Laravel runs after the response was sent — builds and stores the trace.
 *
 * It holds no state: the Tracer singleton does, because Laravel creates a new instance of terminable middleware.
 */
final class RecordRequest
{
    public function __construct(private readonly Tracer $tracer, private readonly Journal $journal) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $this->journal->capture($request);
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
        $this->journal->record($request, $response, $this->tracer->session());       // before the trace is stored and forgotten: it knows the trace id
        Failure::guard(fn () => $this->tracer->finish(), 'storing trace');
    }
}
