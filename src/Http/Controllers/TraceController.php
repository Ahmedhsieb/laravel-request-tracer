<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Controllers;

use Ahmedhsieb\RequestTracer\DeepTrace\Compiler;
use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\Support\Paths;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

/** Read side of the JSON API: traces, their step tree (without payloads), step payloads, lines, writes, problems. */
final class TraceController extends Controller
{
    private const FILTERS = ['search', 'status', 'min_ms', 'max_ms', 'has_problems', 'slow', 'user_id', 'tenant', 'module', 'kind', 'method', 'from', 'to', 'route_name', 'parent_trace_id', 'deep_search'];

    private const MAX_SOURCE_LINES = 800;

    private const MAX_SOURCE_FUNCTIONS = 40;

    public function __construct(private readonly TraceStorage $storage, private readonly Tracer $tracer) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [];
        foreach (self::FILTERS as $name) {
            if ($request->filled($name)) {
                $filters[$name] = $request->query($name);
            }
        }
        foreach (['has_problems', 'slow', 'deep_search'] as $flag) {
            if (isset($filters[$flag])) {
                $filters[$flag] = filter_var($filters[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $filters['slow_ms'] = (float) $this->tracer->config('dashboard.slow_ms', 500);

        $page = $this->storage->traces($filters, $request->query('cursor') ? (string) $request->query('cursor') : null, (int) $request->query('limit', 50));

        return response()->json(['data' => array_map($this->present(...), $page['data']), 'next_cursor' => $page['next_cursor']]);
    }

    public function show(string $trace): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($trace))]);
    }

    public function spans(string $trace): JsonResponse
    {
        $this->find($trace);

        return response()->json(['data' => $this->storage->spans($trace)]);
    }

    public function payload(string $trace, int $span): JsonResponse
    {
        $this->find($trace);
        $payload = $this->storage->payload($trace, $span);
        abort_if($payload === null, 404);

        return response()->json(['data' => [
            'input' => $payload['input'] === null ? null : ['value' => json_decode($payload['input'], true)],
            'output' => $payload['output'] === null ? null : ['value' => json_decode($payload['output'], true)],
        ]]);
    }

    public function lines(string $trace): JsonResponse
    {
        $this->find($trace);

        return response()->json(['data' => $this->storage->lines($trace)]);
    }

    /** Deep trace data of one step, plus the source of the functions it ran (so the dashboard can show "not run" lines). */
    public function spanLines(string $trace, int $span): JsonResponse
    {
        $summary = $this->find($trace);
        $lines = $this->storage->lines($trace, $span);
        $own = null;
        foreach ($this->storage->spans($trace) as $row) {
            if ($row['id'] === $span) {
                $own = is_string($row['file']) && is_int($row['line']) ? [$row['file'], $row['line']] : null;

                break;
            }
        }

        $sources = $this->sources($lines, (string) $summary['created_at'], $own);       // (also moves the rows of a function that was edited since)
        if ($own !== null && ! in_array(true, array_column($sources, 'own'), true)) {
            // The step's own code ran but was not traced line by line (its folder is not in `code.paths`): show its text anyway, without run data.
            $static = $this->staticSource($own[0], $own[1]);
            if ($static !== null) {
                array_unshift($sources, $static);
            }
        }

        return response()->json(['data' => ['lines' => $lines, 'sources' => $sources]]);
    }

    public function writes(string $trace): JsonResponse
    {
        $this->find($trace);

        return response()->json(['data' => $this->storage->writes($trace)]);
    }

    public function problems(string $trace): JsonResponse
    {
        $this->find($trace);

        return response()->json(['data' => $this->storage->problems($trace)]);
    }

    public function destroy(string $trace): JsonResponse
    {
        abort_unless($this->storage->delete($trace), 404);

        return response()->json(['deleted' => 1]);
    }

    public function clear(): JsonResponse
    {
        return response()->json(['deleted' => $this->storage->clear()]);
    }

    /**
     * The database stores `created_at` in UTC without saying so; the browser needs to know,
     * or it reads the time as its own local time ("3 h ago" for a request that just happened).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        if (isset($row['created_at']) && is_string($row['created_at'])) {
            try {
                $row['created_at'] = Carbon::parse($row['created_at'], 'UTC')->toIso8601String();
            } catch (\Throwable) {
                // keep what the database returned
            }
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $trace = $this->storage->trace($id);
        abort_if($trace === null, 404);

        return $trace;
    }

    /**
     * The functions that ran inside a step, the step's own function first and the others in the order they first ran (the rows come
     * sorted by file name, which would put a base class's constructor before the action and cut the action off at the limit).
     *
     * @param  list<array<string, mixed>>  $lines  (changed: the rows of a function whose file was edited after the trace are moved to where their statements are now)
     * @param  array{0: string, 1: int}|null  $own  file and line of the step itself
     * @return list<array<string, mixed>>
     */
    private function sources(array &$lines, string $traceCreatedAt, ?array $own = null): array
    {
        // Only files that appear in this trace's own line data may be read, and only the functions that ran.
        $wanted = [];
        $snippets = [];
        foreach ($lines as $row) {
            $fn = $row['result']['fn'] ?? null;
            if (is_array($fn) && count($fn) === 3) {
                $key = $row['file'].'|'.$fn[1].'|'.$fn[2];
                $snippets[$key][] = [(int) $row['line'], (string) ($row['snippet'] ?? '')];
                $seq = is_int($row['result']['seq'] ?? null) ? $row['result']['seq'] : PHP_INT_MAX;
                $isOwn = $own !== null && $own[0] === $row['file'] && $own[1] >= (int) $fn[1] - 3 && $own[1] <= (int) $fn[2];
                $previous = $wanted[$key] ?? null;
                $wanted[$key] = [(string) $row['file'], (string) $fn[0], (int) $fn[1], (int) $fn[2], min($seq, $previous[4] ?? PHP_INT_MAX), $isOwn || ($previous[5] ?? false)];
            }
        }
        // the step's own function first (the smallest one that contains its line), then in the order of execution
        uasort($wanted, static function (array $a, array $b): int {
            if ($a[5] !== $b[5]) {
                return $a[5] ? -1 : 1;
            }

            return $a[4] <=> $b[4] ?: ($a[3] - $a[2]) <=> ($b[3] - $b[2]);
        });

        $created = Carbon::parse($traceCreatedAt, 'UTC')->getTimestamp();
        clearstatcache();                                   // a long-lived worker would otherwise remember an old modification time
        $sources = [];
        $cache = [];
        $statements = [];
        $shifts = [];
        $mask = (string) $this->tracer->config('masking.mask', '••••••••');
        foreach (array_slice($wanted, 0, self::MAX_SOURCE_FUNCTIONS, true) as $key => [$file, $name, $start, $end, , $isOwn]) {
            $absolute = Paths::absolute($file);
            $cache[$absolute] ??= is_file($absolute) && is_readable($absolute) ? preg_split('/\r\n|\n|\r/', (string) file_get_contents($absolute)) : null;
            $all = $cache[$absolute];
            $changed = $all !== null && ((int) @filemtime($absolute)) > $created;
            // A file edited after the request has its lines somewhere else now: follow the statements by their text.
            $shift = $changed ? self::shiftFor($snippets[$key] ?? [], is_array($all) ? $all : [], $mask) : 0;
            if ($shift !== null && $shift !== 0) {
                $shifts[$key] = $shift;
                $start += $shift;
                $end += $shift;
            }
            $slice = [];
            if (is_array($all)) {
                for ($n = max(1, $start); $n <= min($end, count($all), $start + self::MAX_SOURCE_LINES); $n++) {
                    $slice[] = ['n' => $n, 'text' => $all[$n - 1]];
                }
            }
            $sources[] = [
                'file' => $file,
                'name' => $name,
                'start' => $start,
                'end' => $end,
                'lines' => $slice,
                'statements' => $all === null ? null : self::functionStatements($statements[$absolute] ??= self::probeLines($absolute, $file), $start, $end),
                'own' => $isOwn,
                'shift' => $changed ? $shift : 0,
                'missing' => $all === null,
                'changed_since_trace' => $changed,
            ];
        }

        if ($shifts !== []) {
            foreach ($lines as $i => $row) {
                $fn = $row['result']['fn'] ?? null;
                $key = is_array($fn) && count($fn) === 3 ? $row['file'].'|'.$fn[1].'|'.$fn[2] : null;
                if ($key !== null && isset($shifts[$key])) {
                    $lines[$i]['line'] = (int) $row['line'] + $shifts[$key];
                    $lines[$i]['result']['fn'] = [$fn[0], (int) $fn[1] + $shifts[$key], (int) $fn[2] + $shifts[$key]];
                }
            }
        }

        return $sources;
    }

    private const SHIFT_RANGE = 80;

    /**
     * How far a function moved when its file was edited after the trace. Every probe remembers the text of its statement, so the line
     * that holds that text now, nearest to where it was, says: the most common answer wins. Null when nothing could be matched.
     *
     * @param  list<array{0: int, 1: string}>  $probes  recorded line and statement text
     * @param  list<string>  $all  the lines of the file as it is now
     */
    private static function shiftFor(array $probes, array $all, string $mask): ?int
    {
        $flat = static fn (string $text): string => trim((string) preg_replace('/\s+/', ' ', $text));
        $votes = [];
        $seen = [];
        foreach ($probes as [$line, $snippet]) {
            $needle = $flat((string) preg_replace('/\s*…$/u', '', $snippet));
            if ($snippet === 'arguments' || strlen($needle) < 6 || str_contains($snippet, $mask) || isset($seen[$line.'|'.$needle])) {
                continue;
            }
            $seen[$line.'|'.$needle] = true;
            for ($d = 0; $d <= self::SHIFT_RANGE; $d++) {
                foreach ($d === 0 ? [0] : [$d, -$d] as $delta) {
                    $at = $line + $delta;
                    if ($at >= 1 && $at <= count($all) && str_contains($flat($all[$at - 1]), $needle)) {
                        $votes[$delta] = ($votes[$delta] ?? 0) + 1;

                        continue 3;
                    }
                }
            }
        }
        if ($votes === []) {
            return null;
        }
        $best = null;
        foreach ($votes as $delta => $count) {
            if ($best === null || $count > $votes[$best] || ($count === $votes[$best] && abs($delta) < abs($best))) {
                $best = $delta;
            }
        }

        return $best;
    }

    /**
     * The text of the function that contains a line, for a step whose code was not traced: no run data, only what is written there.
     * Only PHP files inside the application are read.
     *
     * @return array<string, mixed>|null
     */
    private function staticSource(string $file, int $line): ?array
    {
        $absolute = realpath(Paths::absolute($file));
        $base = realpath(Paths::base());
        if ($absolute === false || $base === false || strtolower(pathinfo($absolute, PATHINFO_EXTENSION)) !== 'php' || ! is_readable($absolute)) {
            return null;
        }
        $normalized = Paths::normalize($absolute);
        if (! str_starts_with($normalized, Paths::normalize($base).'/') || str_contains($normalized, '/storage/')) {
            return null;
        }

        $probes = self::probeLines($absolute, $file);
        if ($probes === null) {
            return null;
        }
        $best = null;
        foreach ($probes as [, $from, $to]) {
            if ($line >= $from - 3 && $line <= $to && ($best === null || ($to - $from) < ($best[1] - $best[0]))) {
                $best = [$from, $to];
            }
        }
        if ($best === null) {
            return null;
        }

        $all = preg_split('/\r\n|\n|\r/', (string) file_get_contents($absolute)) ?: [];
        $slice = [];
        for ($n = max(1, $best[0]); $n <= min($best[1], count($all), $best[0] + self::MAX_SOURCE_LINES); $n++) {
            $slice[] = ['n' => $n, 'text' => $all[$n - 1]];
        }
        $name = null;
        foreach ($slice as $row) {
            if (preg_match('/function\s+&?\s*([A-Za-z_]\w*)/', $row['text'], $m) === 1) {
                $name = $m[1];

                break;
            }
        }

        $class = preg_match('/^\s*(?:final\s+|abstract\s+)*class\s+([A-Za-z_]\w*)/m', implode("\n", $all), $c) === 1 ? $c[1] : null;

        return [
            'file' => $file,
            'name' => $name === null ? basename($file) : ($class === null ? $name : $class.'::'.$name),
            'start' => $best[0],
            'end' => $best[1],
            'lines' => $slice,
            'statements' => null,
            'own' => true,
            'untraced' => true,
            'missing' => false,
            'changed_since_trace' => false,
        ];
    }

    /**
     * Where Deep trace puts probes in a file: [[line, functionStart, functionEnd], ...], or null when the file cannot be compiled.
     * The dashboard marks a line "not run" only when a probe sits on it, so continuation lines of a long statement stay unmarked.
     *
     * @return list<array{0: int, 1: int, 2: int}>|null
     */
    private static function probeLines(string $absolute, string $relative): ?array
    {
        try {
            $source = (string) file_get_contents($absolute);
            $result = (new Compiler)->compile($source, $relative, $absolute);
        } catch (\Throwable) {
            return null;
        }

        $lines = [];
        foreach ($result->probes as $probe) {
            if ($probe['f'] !== null) {
                $lines[] = [$probe['l'], $probe['f'][1], $probe['f'][2]];
            }
        }

        return $lines;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>|null  $probes
     * @return list<int>|null
     */
    private static function functionStatements(?array $probes, int $start, int $end): ?array
    {
        if ($probes === null) {
            return null;
        }
        $lines = [];
        foreach ($probes as [$line, $from, $to]) {
            if ($from === $start && $to === $end) {
                $lines[$line] = true;
            }
        }
        ksort($lines);

        return array_keys($lines);
    }
}
