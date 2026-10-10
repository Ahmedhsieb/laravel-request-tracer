<?php

declare(strict_types=1);

namespace RequestTracer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\Support\Paths;
use RequestTracer\Tracer;

/** Read side of the JSON API: traces, their step tree (without payloads), step payloads, lines, writes, problems. */
final class TraceController extends Controller
{
    private const FILTERS = ['search', 'status', 'min_ms', 'max_ms', 'has_problems', 'slow', 'user_id', 'tenant', 'module', 'kind', 'method', 'from', 'to', 'route_name', 'parent_trace_id', 'deep_search'];

    private const MAX_SOURCE_LINES = 800;

    private const MAX_SOURCE_FUNCTIONS = 20;

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

        return response()->json(['data' => $page['data'], 'next_cursor' => $page['next_cursor']]);
    }

    public function show(string $trace): JsonResponse
    {
        return response()->json(['data' => $this->find($trace)]);
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

        return response()->json(['data' => ['lines' => $lines, 'sources' => $this->sources($lines, (string) $summary['created_at'])]]);
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

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $trace = $this->storage->trace($id);
        abort_if($trace === null, 404);

        return $trace;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function sources(array $lines, string $traceCreatedAt): array
    {
        // Only files that appear in this trace's own line data may be read, and only the functions that ran.
        $wanted = [];
        foreach ($lines as $row) {
            $fn = $row['result']['fn'] ?? null;
            if (is_array($fn) && count($fn) === 3) {
                $wanted[$row['file'].'|'.$fn[1].'|'.$fn[2]] = [(string) $row['file'], (string) $fn[0], (int) $fn[1], (int) $fn[2]];
            }
        }

        $created = strtotime($traceCreatedAt) ?: 0;
        $sources = [];
        $cache = [];
        foreach (array_slice($wanted, 0, self::MAX_SOURCE_FUNCTIONS) as [$file, $name, $start, $end]) {
            $absolute = Paths::absolute($file);
            $cache[$absolute] ??= is_file($absolute) && is_readable($absolute) ? preg_split('/\r\n|\n|\r/', (string) file_get_contents($absolute)) : null;
            $all = $cache[$absolute];
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
                'missing' => $all === null,
                'changed_since_trace' => $all !== null && ((int) @filemtime($absolute)) > $created,
            ];
        }

        return $sources;
    }
}
