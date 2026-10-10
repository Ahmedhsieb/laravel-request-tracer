<?php

declare(strict_types=1);

namespace RequestTracer;

/**
 * Collects the spans of one trace.
 *
 * Caps (see config `limits`): once `maxSpans` real spans exist, or once `collapseAfter` identical siblings
 * (same parent, type and label) have been recorded, further spans become "ghosts". A ghost is timed and
 * reports into an aggregate span (count + total time) but is never stored, and everything that happens
 * inside a ghost is swallowed. All per-trace state lives here, so a new Recorder per request is the whole reset.
 */
final class Recorder
{
    /** @var array<int, Span> */
    private array $spans = [];

    /** @var list<Span> */
    private array $stack = [];

    private int $nextId = 1;

    /** @var array<string, int> */
    private array $seen = [];

    /** @var array<string, Span> */
    private array $aggregates = [];

    private int $dropped = 0;

    private bool $capped = false;

    /** Spans created while nothing is open (after the root ended) hang off the first span instead of becoming extra roots. */
    private ?int $rootId = null;

    public function __construct(
        private readonly Clock $clock,
        private readonly int $maxSpans = 5000,
        private readonly int $collapseAfter = 20,
    ) {}

    public function clock(): Clock
    {
        return $this->clock;
    }

    /** Open a span as a child of the current one. Always pair with end() (use try/finally). */
    public function begin(SpanType $type, string $label, ?string $file = null, ?int $line = null): Span
    {
        $parent = $this->stack[count($this->stack) - 1] ?? null;
        $now = $this->clock->nowUs();
        $label = self::clip($label);

        if ($parent !== null && $parent->ghost) {
            return $this->pushGhost($parent->id, $type, $label, $now, null);
        }

        $decision = $this->admit($parent, $type, $label, $now, $file, $line);
        if ($decision !== null) {
            // $decision is the aggregate to merge into, or false when the span cap dropped it
            return $this->pushGhost($decision === false ? ($parent->id ?? 0) : $decision->id, $type, $label, $now, $decision === false ? null : $decision);
        }

        $span = $this->create($parent?->id, $type, $label, $file, $line, $now);
        $this->stack[] = $span;

        return $span;
    }

    public function end(Span $span): void
    {
        $now = $this->clock->nowUs();

        // Close anything left open above $span (a collector forgot to end it).
        while ($this->stack !== [] && $this->stack[count($this->stack) - 1] !== $span) {
            $this->close(array_pop($this->stack), $now);
        }
        if ($this->stack !== []) {
            array_pop($this->stack);
        }
        $this->close($span, $now);
    }

    /**
     * Record a span whose duration is already known (a query, a cache hit, a log line...).
     * Returns a ghost (check `$span->ghost`) when it was merged into an aggregate or dropped; skip capturing payloads then.
     */
    public function leaf(SpanType $type, string $label, int $durationUs = 0, ?string $file = null, ?int $line = null): Span
    {
        $parent = $this->stack[count($this->stack) - 1] ?? null;
        $now = $this->clock->nowUs();
        $label = self::clip($label);
        $durationUs = max(0, $durationUs);
        $start = max(0, $now - $durationUs);

        if ($parent !== null && $parent->ghost) {
            $this->dropped++;

            return $this->makeGhost($parent->id, $type, $label, $start, null);
        }

        $decision = $this->admit($parent, $type, $label, $start, $file, $line);
        if ($decision !== null) {
            $ghost = $this->makeGhost($decision === false ? ($parent->id ?? 0) : $decision->id, $type, $label, $start, $decision === false ? null : $decision);
            if ($decision === false) {
                $this->dropped++;
            }
            $this->close($ghost, $start + $durationUs);

            return $ghost;
        }

        $span = $this->create($parent?->id, $type, $label, $file, $line, $start);
        $this->close($span, $start + $durationUs);

        return $span;
    }

    /** Innermost open span (ghosts included), or null. */
    public function current(): ?Span
    {
        return $this->stack[count($this->stack) - 1] ?? null;
    }

    /** Id of the innermost *stored* span, for attributing writes/problems to something that exists. */
    public function currentId(): ?int
    {
        $top = $this->stack[count($this->stack) - 1] ?? null;

        return $top?->id;
    }

    public function find(int $id): ?Span
    {
        return $this->spans[$id] ?? null;
    }

    /** @return array<int, Span> */
    public function spans(): array
    {
        return $this->spans;
    }

    public function count(): int
    {
        return count($this->spans);
    }

    public function dropped(): int
    {
        return $this->dropped;
    }

    public function capped(): bool
    {
        return $this->capped;
    }

    /**
     * Close everything still open and return the finished spans ordered by id.
     *
     * @return list<Span>
     */
    public function finish(): array
    {
        $now = $this->clock->nowUs();
        while ($this->stack !== []) {
            $this->close(array_pop($this->stack), $now);
        }

        return array_values($this->spans);
    }

    public static function clip(string $label, int $max = 500): string
    {
        $label = preg_replace('/\s+/u', ' ', $label) ?? $label;
        if (strlen($label) <= $max) {
            return $label;
        }

        return (function_exists('mb_strcut') ? mb_strcut($label, 0, $max - 1, 'UTF-8') : substr($label, 0, $max - 1)).'…';
    }

    /**
     * null  = a normal span may be created;
     * Span  = the aggregate the new span must be merged into (collapse);
     * false = the span cap is reached, drop it.
     */
    private function admit(?Span $parent, SpanType $type, string $label, int $now, ?string $file, ?int $line): Span|false|null
    {
        $key = ($parent->id ?? 0).'|'.$type->value.'|'.$label;
        $n = $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;

        if ($n > $this->collapseAfter) {
            $agg = $this->aggregates[$key] ?? null;
            if ($agg === null && count($this->spans) < $this->maxSpans) {
                // The first span over the threshold becomes the aggregate that all later ones report into.
                $agg = $this->create($parent?->id, $type, $label, $file, $line, $now);
                $agg->flag = SpanFlag::Repeated;
                $agg->count = 0;
                $agg->open = false;
                $agg->meta['aggregate'] = true;
                $this->aggregates[$key] = $agg;
            }
            if ($agg !== null) {
                return $agg;
            }
        }

        if (count($this->spans) >= $this->maxSpans) {
            $this->capped = true;

            return false;
        }

        return null;
    }

    private function create(?int $parentId, SpanType $type, string $label, ?string $file, ?int $line, int $startUs): Span
    {
        $parentId ??= $this->rootId;
        $span = new Span($this->nextId++, $parentId, $type, $label, $file, $line, $startUs);
        $this->rootId ??= $span->id;
        $span->memStart = memory_get_usage();
        $this->spans[$span->id] = $span;

        return $span;
    }

    private function makeGhost(int $holderId, SpanType $type, string $label, int $startUs, ?Span $into): Span
    {
        $ghost = new Span($holderId, null, $type, $label, null, null, $startUs);
        $ghost->ghost = true;
        $ghost->collapseInto = $into;

        return $ghost;
    }

    private function pushGhost(int $holderId, SpanType $type, string $label, int $now, ?Span $into): Span
    {
        $ghost = $this->makeGhost($holderId, $type, $label, $now, $into);
        $this->stack[] = $ghost;
        if ($into === null) {
            $this->dropped++;
        }

        return $ghost;
    }

    private function close(Span $span, int $nowUs): void
    {
        $duration = max(0, $nowUs - $span->startUs);

        if ($span->ghost) {
            $agg = $span->collapseInto;
            if ($agg !== null) {
                $agg->count++;
                $agg->durationUs += $duration;
            }
            $span->open = false;

            return;
        }

        $span->durationUs += $duration;
        $span->memoryDelta = memory_get_usage() - $span->memStart;
        $span->open = false;
    }
}
