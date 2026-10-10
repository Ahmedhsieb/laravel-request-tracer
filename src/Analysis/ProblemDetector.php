<?php

declare(strict_types=1);

namespace RequestTracer\Analysis;

use RequestTracer\Span;
use RequestTracer\TraceSession;

/**
 * Looks at a finished trace and adds problems (exceptions, N+1, repeated and slow queries, slow steps, failed HTTP calls,
 * truncation). Scaffold in phase 1 (truncation only); the full detector arrives with phase 2.
 */
final class ProblemDetector
{
    /** @param  list<Span>  $spans */
    public function detect(TraceSession $session, array $spans): void
    {
        if ($session->truncated) {
            $dropped = $session->recorder->dropped();
            $session->problems[] = [
                'span_id' => $session->root?->id,
                'kind' => 'truncated',
                'message' => $dropped > 0
                    ? sprintf('The trace hit its span cap: %d spans were not recorded.', $dropped)
                    : 'The trace hit a recording cap; some data was not recorded.',
                'file' => null,
                'line' => null,
            ];
        }
    }
}
