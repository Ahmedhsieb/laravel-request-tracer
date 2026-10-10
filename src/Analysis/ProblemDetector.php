<?php

declare(strict_types=1);

namespace RequestTracer\Analysis;

use RequestTracer\Span;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;
use RequestTracer\Support\App;
use RequestTracer\TraceSession;

/**
 * Looks at a finished trace and adds problems: exceptions (caught or not), N+1 and repeated queries, slow queries,
 * slow steps, failed HTTP calls and truncation. Runs once per trace in terminate(); thresholds live in `problems.*`.
 */
final class ProblemDetector
{
    private const MAX_PROBLEMS = 100;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @param  list<Span>  $spans */
    public function detect(TraceSession $session, array $spans): void
    {
        $this->config = (array) App::config()->get('request-tracer.problems', []);

        $this->exceptions($session);
        $this->flagExceptionSpans($session, $spans);
        $this->queries($session);
        $this->slowSteps($session, $spans);
        $this->httpFailures($session);

        if ($session->truncated) {
            $dropped = $session->recorder->dropped();
            $this->add($session, $session->root?->id, 'truncated', $dropped > 0
                ? sprintf('The trace hit its span cap: %d spans were not recorded.', $dropped)
                : 'The trace hit a recording cap; some data was not recorded.');
        }
    }

    private function exceptions(TraceSession $session): void
    {
        $status = $session->meta['status'] ?? null;
        foreach ($session->exceptions()->records() as $r) {
            if ($r->uncaught && $r->ignore) {
                continue;
            }
            $short = $r->class.': '.$r->message;
            if ($r->uncaught) {
                $this->add($session, $r->span, 'exception_uncaught', $short.' — not caught; the request failed'.($status !== null ? " (response {$status})" : '').'.', $r->file, $r->line);
            } else {
                $this->add($session, $r->span, 'exception_caught', $short.' — caught by application code, so the request continued'.($status !== null ? " (the response was still {$status})" : '').'.', $r->file, $r->line);
            }
        }
    }

    /**
     * Spans an exception propagated through are `failed` when it ended the request and `caught` when application code handled it.
     *
     * @param  list<Span>  $spans
     */
    private function flagExceptionSpans(TraceSession $session, array $spans): void
    {
        $records = $session->exceptions()->records();
        foreach ($spans as $span) {
            $id = $span->meta['exception'] ?? null;
            if (! is_int($id) || ! isset($records[$id])) {
                continue;
            }
            $record = $records[$id];
            $span->flag = $record->uncaught ? ($record->ignore ? null : SpanFlag::Failed) : SpanFlag::Caught;
        }
    }

    private function queries(TraceSession $session): void
    {
        $stats = $session->queryStats();
        $nPlusOne = max(2, (int) ($this->config['n_plus_one_threshold'] ?? 5));
        $repeated = max(2, (int) ($this->config['repeated_query_threshold'] ?? 2));
        $repeatedWrite = max(2, (int) ($this->config['repeated_write_threshold'] ?? 3));

        foreach ($stats->shapes() as $shape) {
            $count = $shape['count'];
            $distinct = count($shape['sets']);
            $where = $shape['file'] !== null ? ' at '.$shape['file'].($shape['line'] !== null ? ':'.$shape['line'] : '') : '';
            $sql = self::shorten($shape['sql']);
            $span = $session->recorder->find($shape['span']);
            $op = $shape['op'];

            if ($op === 'select') {
                if ($count >= $nPlusOne && $distinct >= $nPlusOne) {
                    $this->add($session, $shape['span'], 'n_plus_one', "The same SELECT ran {$count} times with {$distinct} different values{$where} (N+1): {$sql}. Load it once with eager loading or a whereIn().", $shape['file'], $shape['line']);
                    $this->flag($span, SpanFlag::Repeated);
                }
                $max = $shape['sets'] === [] ? 0 : max($shape['sets']);
                if ($max >= $repeated) {
                    $this->add($session, $shape['span'], 'repeated_query', "The identical query ran {$max} times{$where}: {$sql}. Run it once and reuse the result.", $shape['file'], $shape['line']);
                    $this->flag($span, SpanFlag::Repeated);
                }
            } elseif (in_array($op, ['insert', 'update', 'delete'], true) && $count >= $repeatedWrite) {
                $this->add($session, $shape['span'], 'repeated_query', 'The same '.strtoupper($op)." ran {$count} times{$where} (in a loop?): {$sql}. Consider one batched statement.", $shape['file'], $shape['line']);
                $this->flag($span, SpanFlag::Repeated);
            }
        }

        foreach ($stats->slow() as $slow) {
            $this->add($session, $slow['span'], 'slow_query', "Query took {$slow['ms']} ms: ".self::shorten($slow['sql']), $slow['file'], $slow['line']);
        }
    }

    /** @param  list<Span>  $spans */
    private function slowSteps(TraceSession $session, array $spans): void
    {
        $total = $session->root !== null ? max(1, $session->root->durationUs) : 1;
        $minMs = (float) ($this->config['slow_step_ms'] ?? 500);
        $share = (float) ($this->config['slow_step_share'] ?? 0.4);
        $shareMin = (float) ($this->config['slow_step_min_ms'] ?? 50);

        $children = [];
        foreach ($spans as $s) {
            if ($s->parentId !== null) {
                $children[$s->parentId] = ($children[$s->parentId] ?? 0) + $s->durationUs;
            }
        }

        foreach ($spans as $s) {
            if (! in_array($s->type, [SpanType::Service, SpanType::Controller, SpanType::Middleware, SpanType::Http, SpanType::Job, SpanType::Validation], true)) {
                continue;
            }
            $self = max(0, $s->durationUs - ($children[$s->id] ?? 0));
            $selfMs = $self / 1000;
            $fraction = $self / $total;
            if ($selfMs >= $minMs || ($fraction >= $share && $selfMs >= $shareMin)) {
                $this->add($session, $s->id, 'slow_step', sprintf('%s spent %.0f ms by itself, %d%% of the request.', $s->label, $selfMs, (int) round($fraction * 100)), $s->file, $s->line);
                $this->flag($s, SpanFlag::Slow);
            }
        }
    }

    private function httpFailures(TraceSession $session): void
    {
        /** @var list<array{span: int, failed: bool, status: int|null, label: string, file: string|null, line: int|null}> $calls */
        $calls = $session->bag['http_stats'] ?? [];
        foreach ($calls as $call) {
            if ($call['failed']) {
                $what = $call['status'] === null ? 'could not connect' : 'returned HTTP '.$call['status'];
                $this->add($session, $call['span'], 'http_failed', "{$call['label']} {$what}.", $call['file'], $call['line']);
            }
        }
    }

    private function add(TraceSession $session, ?int $spanId, string $kind, string $message, ?string $file = null, ?int $line = null): void
    {
        if (count($session->problems) >= self::MAX_PROBLEMS) {
            return;
        }
        $session->problems[] = ['span_id' => $spanId, 'kind' => $kind, 'message' => $message, 'file' => $file, 'line' => $line];
    }

    private function flag(?Span $span, SpanFlag $flag): void
    {
        if ($span === null || $span->ghost) {
            return;
        }
        if ($span->flag === null || ($span->flag === SpanFlag::Caught && $flag === SpanFlag::Slow)) {
            $span->flag = $flag;
        }
    }

    private static function shorten(string $sql): string
    {
        $sql = trim((string) preg_replace('/\s+/', ' ', $sql));

        return strlen($sql) > 140 ? substr($sql, 0, 137).'…' : $sql;
    }
}
