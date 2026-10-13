<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

/**
 * Monotonic clock for one trace. Times are microseconds since the start of the request (LARAVEL_START when known).
 * The time the tracer itself spends (dumping values etc.) is subtracted, so spans show the application's time, not ours.
 */
final class Clock
{
    private int $baseNs;

    private int $overheadNs = 0;

    public readonly float $wallStart;

    public function __construct(?float $wallStart = null)
    {
        $nowWall = microtime(true);
        $wallStart = ($wallStart !== null && $wallStart > 0 && $wallStart <= $nowWall) ? $wallStart : $nowWall;
        $this->wallStart = $wallStart;
        $this->baseNs = hrtime(true) - (int) (($nowWall - $wallStart) * 1_000_000_000);
    }

    public function nowUs(): int
    {
        return intdiv(hrtime(true) - $this->baseNs - $this->overheadNs, 1000);
    }

    public function addOverhead(int $ns): void
    {
        $this->overheadNs += $ns;
    }
}
