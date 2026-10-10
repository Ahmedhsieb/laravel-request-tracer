<?php

declare(strict_types=1);

namespace RequestTracer;

/**
 * One step of a trace. Plain data: collectors create spans through the Recorder, storage reads them.
 *
 * `input`/`output` hold JSON produced by the ValueDumper (already masked and size-limited), or null when
 * nothing was captured. The JSON literal "null" means "the value was null".
 */
final class Span
{
    public ?string $input = null;

    public ?string $output = null;

    public ?SpanFlag $flag = null;

    public ?string $connection = null;

    /** Number of identical spans this one stands for (>1 once collapsed). */
    public int $count = 1;

    /** @var array<string, mixed> small structured extras (status code, sql kind, exception id...) */
    public array $meta = [];

    public int $durationUs = 0;

    public int $memoryDelta = 0;

    /** internal: memory usage when the span started */
    public int $memStart = 0;

    public bool $open = true;

    /** internal: spans created past a cap are "ghosts": timed, but never stored; their subtree is swallowed. */
    public bool $ghost = false;

    /** internal: the aggregate span a ghost reports into */
    public ?Span $collapseInto = null;

    public function __construct(
        public readonly int $id,
        public readonly ?int $parentId,
        public readonly SpanType $type,
        public string $label,
        public ?string $file,
        public ?int $line,
        public int $startUs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parentId,
            'type' => $this->type->value,
            'label' => $this->label,
            'file' => $this->file,
            'line' => $this->line,
            'start_us' => $this->startUs,
            'duration_us' => $this->durationUs,
            'memory_delta' => $this->memoryDelta,
            'flag' => $this->flag?->value,
            'connection' => $this->connection,
            'count' => $this->count,
            'meta' => $this->meta,
            'input' => $this->input,
            'output' => $this->output,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        $span = new self(
            (int) $a['id'],
            isset($a['parent_id']) ? (int) $a['parent_id'] : null,
            SpanType::from((string) $a['type']),
            (string) $a['label'],
            $a['file'] ?? null,
            isset($a['line']) ? (int) $a['line'] : null,
            (int) $a['start_us'],
        );
        $span->durationUs = (int) $a['duration_us'];
        $span->memoryDelta = (int) $a['memory_delta'];
        $span->flag = isset($a['flag']) ? SpanFlag::from((string) $a['flag']) : null;
        $span->connection = $a['connection'] ?? null;
        $span->count = (int) ($a['count'] ?? 1);
        $span->meta = (array) ($a['meta'] ?? []);
        $span->input = $a['input'] ?? null;
        $span->output = $a['output'] ?? null;
        $span->open = false;

        return $span;
    }

    /** @return mixed decoded input (null when nothing captured) */
    public function inputValue(): mixed
    {
        return $this->input === null ? null : json_decode($this->input, true);
    }

    /** @return mixed decoded output (null when nothing captured) */
    public function outputValue(): mixed
    {
        return $this->output === null ? null : json_decode($this->output, true);
    }
}
