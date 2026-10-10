<?php

declare(strict_types=1);
use RequestTracer\Tracer;

if (! function_exists('trace')) {
    /**
     * Record $value as a span (in dd() format) and return it unchanged, so it can wrap any expression:
     *
     *     return trace($this->calculate($order), 'order total');
     *
     * No-op (returns $value) when no recording is active.
     *
     * @template TValue
     *
     * @param  TValue  $value
     * @return TValue
     */
    function trace(mixed $value, ?string $label = null): mixed
    {
        return Tracer::helper($value, $label);
    }
}
