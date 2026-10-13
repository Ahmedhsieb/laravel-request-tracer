<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

/**
 * The hot-path flag for code that cannot reach the Tracer cheaply (generated proxies, Deep trace probes, trace()).
 * Exactly one static boolean, set by the Tracer when a trace starts and cleared when it ends (or is reset).
 * It is the only static state in the package that changes at runtime, and it never outlives a request.
 */
final class Hot
{
    public static bool $on = false;
}
