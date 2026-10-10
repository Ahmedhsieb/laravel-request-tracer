<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use RequestTracer\TraceSession;

/**
 * Turns the aggregated probe hits of a trace into `tracer_lines` rows:
 * (span, file, line, kind, hits, result JSON, snippet). The result JSON depends on the kind:
 *
 *   cond    {"true": n, "false": n, "samples": [{"a": value, "b": value, "op": ">", "result": bool}]}   or {"values": {...}, "last": value} for match/switch subjects
 *   block   {} (hits = how often the block ran); a catch block also has "caught": the exception; a match arm has "last": its value
 *   assign  {"changes": n, "first": value, "last": value}
 *   loop    {"iterations": n, "snapshots": [{"var": value}, ...]}
 *   return  {"value": value}
 *   throw   {"exception": value}
 *
 * Every result also carries "seq" (order of first execution, for the replay) and "fn" ([name, firstLine, lastLine]).
 */
final class LinesBuilder
{
    /**
     * @return array{rows: list<array<string, mixed>>, files: list<string>}
     */
    public static function build(TraceSession $session, ProbeMap $map, int $maxRows): array
    {
        $rows = [];
        $files = [];
        if (! $session->hasDeepData()) {
            return ['rows' => [], 'files' => []];
        }

        foreach ($session->deep()->rows() as $row) {
            if (count($rows) >= $maxRows) {
                $session->truncated = true;

                break;
            }
            $meta = $map->resolve((int) $row['probe']);
            if ($meta === null) {
                continue;
            }
            $files[$meta['path']] = true;

            $result = ['seq' => $row['seq']];
            if ($meta['function'] !== null) {
                $result['fn'] = $meta['function'];
            }
            if ($meta['name'] !== null) {
                $result['name'] = $meta['name'];
            }

            switch ($row['kind']) {
                case ProbeKind::COND:
                    foreach (['true', 'false'] as $k) {
                        if (isset($row[$k])) {
                            $result[$k] = $row[$k];
                        }
                    }
                    foreach ($row['samples'] ?? [] as $sample) {
                        $result['samples'][] = [
                            'a' => self::decode($sample['a'] ?? null),
                            'b' => self::decode($sample['b'] ?? null),
                            'op' => $sample['op'] ?? null,
                            'result' => $sample['result'] ?? null,
                        ];
                    }
                    if (isset($row['values'])) {
                        $result['values'] = $row['values'];
                    }
                    if (isset($row['last'])) {
                        $result['last'] = self::decode($row['last']);
                    }
                    break;

                case ProbeKind::ASSIGN:
                    $result['changes'] = $row['changes'] ?? $row['hits'];
                    foreach (['first', 'last'] as $k) {
                        if (isset($row[$k])) {
                            $result[$k] = self::decode($row[$k]);
                        }
                    }
                    break;

                case ProbeKind::LOOP:
                    $result['iterations'] = $row['hits'];
                    foreach ($row['snapshots'] ?? [] as $snapshot) {
                        $result['snapshots'][] = self::decode($snapshot);
                    }
                    break;

                case ProbeKind::RETURN:
                    if (isset($row['last'])) {
                        $result['value'] = self::decode($row['last']);
                    }
                    break;

                case ProbeKind::THROW:
                    if (isset($row['last'])) {
                        $result['exception'] = self::decode($row['last']);
                    }
                    break;

                default:                                                // block
                    foreach (['caught', 'last'] as $k) {
                        if (isset($row[$k])) {
                            $result[$k] = self::decode($row[$k]);
                        }
                    }
            }

            $rows[] = [
                'span_id' => (int) $row['span'] > 0 ? (int) $row['span'] : null,
                'file' => $meta['path'],
                'line' => $meta['line'],
                'kind' => $meta['kind'] === 'cond' && $row['kind'] === ProbeKind::COND ? ProbeKind::COND : (string) $row['kind'],
                'hits' => (int) $row['hits'],
                'result' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                'snippet' => mb_substr(self::safeSnippet($meta['snippet'], $session, $meta['name']), 0, 250),
            ];
        }

        return ['rows' => $rows, 'files' => array_keys($files)];
    }

    /**
     * A snippet is source text. If the statement talks about a secret (`$password`, `->apiToken`), string literals in it are masked,
     * so a hard-coded credential in the code never reaches storage through the snippet.
     */
    private static function safeSnippet(string $snippet, TraceSession $session, ?string $name): string
    {
        $masker = $session->dumper->masker();
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $snippet, $words) === false) {
            return $snippet;
        }
        // names the probe itself knows about (assigned variable, compared operands, the function a return belongs to)
        $known = $name === null ? [] : preg_split('/[,:]+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        foreach (array_merge($words[0], $known === false ? [] : $known) as $word) {
            if ($masker->isSensitiveKey($word)) {
                $replaced = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/', "'".$masker->mask."'", $snippet);

                return $replaced ?? $snippet;
            }
        }

        return $snippet;
    }

    /** Values are stored as dumper JSON strings; the result embeds them as structures. */
    private static function decode(mixed $json): mixed
    {
        return is_string($json) ? json_decode($json, true) : $json;
    }
}
