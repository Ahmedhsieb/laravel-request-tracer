<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer;

/**
 * Everything one finished trace consists of, ready to be persisted by a TraceStorage.
 *
 * `trace` holds the `traces` row (JSON columns already encoded), the other lists hold plain rows:
 *  - lines:    ['span_id','file','line','kind','hits','result' (json|null),'snippet']
 *  - writes:   ['span_id','connection','table','operation','row_key','before' (json|null),'after' (json|null),'transaction','rows']
 *  - problems: ['span_id','kind','message','file','line']
 */
final class TraceData
{
    /**
     * @param  array<string, mixed>  $trace
     * @param  list<Span>  $spans
     * @param  list<array<string, mixed>>  $lines
     * @param  list<array<string, mixed>>  $writes
     * @param  list<array<string, mixed>>  $problems
     */
    public function __construct(
        public array $trace,
        public array $spans = [],
        public array $lines = [],
        public array $writes = [],
        public array $problems = [],
    ) {}

    /** @return array<string, mixed> JSON-safe form (used to ship a trace to a queued job) */
    public function toArray(): array
    {
        $trace = $this->trace;
        if ($trace['created_at'] instanceof \DateTimeInterface) {
            $trace['created_at'] = $trace['created_at']->format('Y-m-d H:i:s');
        }

        return [
            'trace' => $trace,
            'spans' => array_map(static fn (Span $s): array => $s->toArray(), $this->spans),
            'lines' => $this->lines,
            'writes' => $this->writes,
            'problems' => $this->problems,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (array) $a['trace'],
            array_values(array_map(static fn (array $s): Span => Span::fromArray($s), (array) $a['spans'])),
            array_values((array) $a['lines']),
            array_values((array) $a['writes']),
            array_values((array) $a['problems']),
        );
    }

    public function id(): string
    {
        return (string) $this->trace['id'];
    }
}
