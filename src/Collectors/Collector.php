<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Contracts\Foundation\Application;
use RequestTracer\Tracer;

/**
 * A collector turns framework events into spans. Register listeners once at boot; every listener starts with
 * `if (! $tracer->on) { return; }` so a disabled or idle tracer costs one boolean check per event.
 *
 * Third-party collectors can implement this interface and be listed in `request-tracer.collectors` (see docs/11-extending.md):
 * a key mapped to a class name, e.g. 'billing' => App\Tracing\BillingCollector::class.
 */
interface Collector
{
    /** Config key under `request-tracer.collectors` that switches this collector on or off. */
    public static function key(): string;

    public function register(Tracer $tracer, Application $app): void;
}
