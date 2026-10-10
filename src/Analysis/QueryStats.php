<?php

declare(strict_types=1);

namespace RequestTracer\Analysis;

/**
 * Per-trace aggregation of queries, independent of how many spans were stored (spans collapse, these counters do not).
 * Feeds the N+1 / repeated / slow query detection.
 */
final class QueryStats
{
    private const MAX_SHAPES = 500;

    private const MAX_BINDING_SETS = 200;

    /** @var array<string, array{sql: string, op: string, count: int, sets: array<string, int>, file: string|null, line: int|null, span: int, us: int}> */
    private array $shapes = [];

    /** @var list<array{span: int, ms: float, sql: string, file: string|null, line: int|null}> */
    private array $slow = [];

    /**
     * @param  list<mixed>  $bindings
     */
    public function record(string $fingerprint, string $sql, string $op, array $bindings, ?string $file, ?int $line, int $spanId, int $us, float $slowMs): void
    {
        $key = $fingerprint.'|'.$file.':'.$line;
        if (! isset($this->shapes[$key])) {
            if (count($this->shapes) >= self::MAX_SHAPES) {
                return;
            }
            $this->shapes[$key] = ['sql' => $sql, 'op' => $op, 'count' => 0, 'sets' => [], 'file' => $file, 'line' => $line, 'span' => $spanId, 'us' => 0];
        }
        $shape = &$this->shapes[$key];
        $shape['count']++;
        $shape['us'] += $us;

        $hash = md5(serialize($bindings));
        if (isset($shape['sets'][$hash])) {
            $shape['sets'][$hash]++;
        } elseif (count($shape['sets']) < self::MAX_BINDING_SETS) {
            $shape['sets'][$hash] = 1;
        }

        if ($us / 1000 >= $slowMs && count($this->slow) < 25) {
            $this->slow[] = ['span' => $spanId, 'ms' => round($us / 1000, 2), 'sql' => $sql, 'file' => $file, 'line' => $line];
        }
    }

    /** @return array<string, array{sql: string, op: string, count: int, sets: array<string, int>, file: string|null, line: int|null, span: int, us: int}> */
    public function shapes(): array
    {
        return $this->shapes;
    }

    /** @return list<array{span: int, ms: float, sql: string, file: string|null, line: int|null}> */
    public function slow(): array
    {
        return $this->slow;
    }
}
