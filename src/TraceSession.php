<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

use Ahmedhsieb\RequestTracer\Analysis\ExceptionTracker;
use Ahmedhsieb\RequestTracer\Analysis\QueryStats;
use Ahmedhsieb\RequestTracer\Analysis\WriteTracker;
use Ahmedhsieb\RequestTracer\DeepTrace\ProbeStore;
use Ahmedhsieb\RequestTracer\Dumper\ValueDumper;
use Ahmedhsieb\RequestTracer\Support\App;

/**
 * Everything that belongs to the trace currently being recorded. The Tracer creates one at the start of a request
 * (or job / command) and drops it at the end, which is the entire per-request reset: nothing in here outlives the request.
 */
final class TraceSession
{
    public ?Span $root = null;

    /** @var array<string, mixed> method, path, route_name, status, user_id, tenant, module, ip_hash ... */
    public array $meta = [];

    public int $queries = 0;

    /** @var list<array<string, mixed>> */
    public array $writes = [];

    /** @var list<array<string, mixed>> */
    public array $problems = [];

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** @var array<string, array{queries: int, rows: int}> table => read counters */
    public array $tablesRead = [];

    /** @var array<string, mixed> free storage for collectors that need per-request state (reset with the session) */
    public array $bag = [];

    /** @var list<array{kind: string, target: string, message: string}> things the dashboard should tell the user ("not traceable: final", compile warnings) */
    public array $notes = [];

    public bool $truncated = false;

    public ?string $parentTraceId = null;

    public bool $discard = false;

    private ?WriteTracker $writeTracker = null;

    private ?ExceptionTracker $exceptionTracker = null;

    private ?QueryStats $queryStats = null;

    private ?ProbeStore $probeStore = null;

    public function __construct(
        public readonly string $id,
        public readonly string $kind,
        public RecordingState $state,
        public string $trigger,
        public readonly Clock $clock,
        public readonly Recorder $recorder,
        public readonly ValueDumper $dumper,
    ) {}

    public function note(string $kind, string $target, string $message): void
    {
        foreach ($this->notes as $n) {
            if ($n['kind'] === $kind && $n['target'] === $target && $n['message'] === $message) {
                return;
            }
        }
        if (count($this->notes) < 200) {
            $this->notes[] = ['kind' => $kind, 'target' => $target, 'message' => $message];
        }
    }

    /** Deep trace hits of this trace. */
    public function deep(): ProbeStore
    {
        $unlimited = (bool) App::config()->get('request-tracer.unlimited', false);

        return $this->probeStore ??= new ProbeStore(
            $this,
            $unlimited ? PHP_INT_MAX : max(100, (int) App::config()->get('request-tracer.deep_trace.max_hits', 20000)),
            max(1, (int) App::config()->get('request-tracer.deep_trace.loop_snapshots', 5)),
            guardMemory: $unlimited,
        );
    }

    public function hasDeepData(): bool
    {
        return $this->probeStore !== null && ! $this->probeStore->isEmpty();
    }

    public function writeTracker(): WriteTracker
    {
        return $this->writeTracker ??= new WriteTracker($this, $this->dumper->masker(), App::config()->get('request-tracer.unlimited', false) ? PHP_INT_MAX : (int) App::config()->get('request-tracer.limits.max_writes', 2000));
    }

    public function exceptions(): ExceptionTracker
    {
        return $this->exceptionTracker ??= new ExceptionTracker($this);
    }

    public function queryStats(): QueryStats
    {
        return $this->queryStats ??= new QueryStats;
    }

    /**
     * Dump a value for storage. The time spent is subtracted from the timeline so spans show the application's time only.
     */
    public function dump(mixed $value): string
    {
        $t = hrtime(true);
        try {
            return $this->dumper->dump($value);
        } finally {
            $this->clock->addOverhead(hrtime(true) - $t);
        }
    }
}
