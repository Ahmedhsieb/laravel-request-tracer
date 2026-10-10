<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

/**
 * Text edits applied to a source string at original byte offsets.
 *
 * Deep trace only ever inserts text (plus swapping a comparison operator for a comma and `__FILE__` for a literal) and none of
 * it contains a line break, so every original line stays on its original line number.
 *
 * Several insertions can land on the same offset (the end of an expression closes three nested wrappers; the start of a
 * statement opens a brace, a probe statement and a wrapper). They are ordered so that brackets nest properly:
 *   1. closers, innermost first (the wrapper that was opened last closes first),
 *   2. a `{` added around a brace-less body,
 *   3. probe statements,
 *   4. openers, outermost first (the longer range opens first).
 */
final class Edits
{
    private const BAND_CLOSE = 0;

    private const BAND_BRACE = 1;

    private const BAND_STATEMENT = 2;

    private const BAND_OPEN = 3;

    /** @var array<int, list<array{0: int, 1: int, 2: int, 3: string}>> offset => [band, k1, k2, text] */
    private array $inserts = [];

    /** @var array<int, array{0: int, 1: string}> start => [end (exclusive), text] */
    private array $replaces = [];

    private int $sequence = 0;

    private int $count = 0;

    /** Wrap [start, end) in `$open` ... `$close`. */
    public function wrap(int $start, int $end, string $open, string $close): void
    {
        $seq = ++$this->sequence;
        $this->put($start, self::BAND_OPEN, -$end, $seq, $open);
        $this->put($end, self::BAND_CLOSE, -$start, -$seq, $close);
    }

    /** Add `{` ... `}` around a brace-less body. */
    public function braces(int $start, int $end): void
    {
        $seq = ++$this->sequence;
        $this->put($start, self::BAND_BRACE, 0, $seq, '{');
        $this->put($end, self::BAND_CLOSE, -$start, -$seq, '}');
    }

    /** Insert a probe statement before the statement that starts at $offset. Lower $rank goes first. */
    public function statement(int $offset, string $text, int $rank = 0): void
    {
        $this->put($offset, self::BAND_STATEMENT, $rank, ++$this->sequence, $text);
    }

    /** Insert text that has to sit between existing tokens (e.g. just after a `{`). */
    public function insertAfterBrace(int $offset, string $text): void
    {
        $this->put($offset, self::BAND_STATEMENT, 0, ++$this->sequence, $text);
    }

    public function replace(int $start, int $endExclusive, string $text): void
    {
        if (str_contains($text, "\n") || str_contains($text, "\r") || isset($this->replaces[$start])) {
            throw new CompileException('internal error: invalid replacement');
        }
        $this->replaces[$start] = [$endExclusive, $text];
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return array<int, int> start => end (exclusive) of every replaced range */
    public function replacedRanges(): array
    {
        return array_map(static fn (array $r): int => $r[0], $this->replaces);
    }

    public function apply(string $source): string
    {
        $points = array_unique(array_merge(array_keys($this->inserts), array_keys($this->replaces)));
        sort($points);

        $out = '';
        $cursor = 0;
        foreach ($points as $point) {
            if ($point < $cursor) {
                throw new CompileException('internal error: an edit falls inside a replaced range');
            }
            $out .= substr($source, $cursor, $point - $cursor);
            $cursor = $point;

            if (isset($this->inserts[$point])) {
                $list = $this->inserts[$point];
                usort($list, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
                foreach ($list as $item) {
                    $out .= $item[3];
                }
            }
            if (isset($this->replaces[$point])) {
                [$end, $text] = $this->replaces[$point];
                $out .= $text;
                $cursor = $end;
            }
        }

        return $out.substr($source, $cursor);
    }

    private function put(int $offset, int $band, int $k1, int $k2, string $text): void
    {
        if (str_contains($text, "\n") || str_contains($text, "\r")) {
            throw new CompileException('internal error: a probe contained a line break');
        }
        $this->inserts[$offset][] = [$band, $k1, $k2, $text];
        $this->count++;
    }
}
