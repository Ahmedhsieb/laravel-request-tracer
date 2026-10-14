<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\DeepTrace;

use Ahmedhsieb\RequestTracer\Support\MemoryGuard;
use Ahmedhsieb\RequestTracer\TraceSession;

/**
 * Aggregated probe hits of one trace, keyed by (span, probe). Hits are counted, not logged: a probe inside a loop that runs
 * a million times costs one array update per hit and one row in storage. After `deep_trace.max_hits` hits (all probes together)
 * recording stops and the trace is marked truncated.
 */
final class ProbeStore
{
    private int $total = 0;

    private int $sequence = 0;

    private bool $full = false;

    /** @var array<string, array<string, mixed>> "span:probe" => aggregate */
    private array $rows = [];

    public function __construct(
        private readonly TraceSession $session,
        private readonly int $maxHits,
        private readonly int $snapshots,
        private readonly int $valueSamples = 50,
        /** `unlimited` mode: no cap on the hits, but they stop once the process is running out of memory */
        private readonly bool $guardMemory = false,
    ) {}

    /**
     * Count one hit. Returns the aggregate row to fill in, or null once the cap was reached.
     *
     * @return array<string, mixed>|null
     */
    public function &hit(int $probe, string $kind): ?array
    {
        $null = null;
        if ($this->full) {
            return $null;
        }
        if (++$this->total > $this->maxHits || ($this->guardMemory && ($this->total & 1023) === 0 && MemoryGuard::low())) {
            $this->full = true;
            $this->session->truncated = true;

            return $null;
        }

        $span = $this->session->recorder->currentId() ?? 0;
        $key = $span.':'.$probe;
        if (! isset($this->rows[$key])) {
            $this->rows[$key] = ['span' => $span, 'probe' => $probe, 'kind' => $kind, 'hits' => 0, 'seq' => ++$this->sequence];
        }
        $this->rows[$key]['hits']++;

        return $this->rows[$key];
    }

    public function snapshotLimit(): int
    {
        return $this->snapshots;
    }

    public function sampleLimit(): int
    {
        return $this->valueSamples;
    }

    public function totalHits(): int
    {
        return $this->total;
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function isEmpty(): bool
    {
        return $this->rows === [];
    }
}
