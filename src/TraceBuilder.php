<?php

declare(strict_types=1);

namespace RequestTracer;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use RequestTracer\Analysis\ProblemDetector;
use RequestTracer\Support\Failure;

/**
 * Turns a finished TraceSession into the rows storage persists. Runs in terminate(), after the response was sent.
 */
final class TraceBuilder
{
    public function __construct(
        private readonly Application $app,
        private readonly Tracer $tracer,
    ) {}

    public function build(TraceSession $session): TraceData
    {
        $spans = $session->recorder->finish();
        $root = $session->root;
        $durationUs = $root !== null ? $root->durationUs : (int) max(0, $session->clock->nowUs());

        if ($session->recorder->capped()) {
            $session->truncated = true;
        }

        (new ProblemDetector)->detect($session, $spans);

        $request = $this->app->bound('request') ? $this->app->make('request') : null;
        $context = $this->tracer->resolveContext($request instanceof Request ? $request : null);
        $userId = $session->meta['user_id'] ?? $this->userId($context['user']);

        $trace = [
            'id' => $session->id,
            'kind' => $session->kind,
            'method' => $session->meta['method'] ?? null,
            'path' => isset($session->meta['path']) ? $this->clip((string) $session->meta['path'], 512) : null,
            'route_name' => isset($session->meta['route_name']) ? $this->clip((string) $session->meta['route_name'], 255) : null,
            'status' => $session->meta['status'] ?? null,
            'duration_ms' => round($durationUs / 1000, 2),
            'memory_peak' => memory_get_peak_usage(true),
            'user_id' => $userId === null ? null : $this->clip((string) $userId, 64),
            'tenant' => $context['tenant'] === null ? null : $this->clip((string) $context['tenant'], 64),
            'module' => ($session->meta['module'] ?? $context['module']) === null ? null : $this->clip((string) ($session->meta['module'] ?? $context['module']), 64),
            'ip_hash' => $session->meta['ip_hash'] ?? null,
            'spans_count' => count($spans),
            'queries_count' => $session->queries,
            'problems_count' => count($session->problems),
            'tables_written' => $this->json($this->tablesWritten($session)),
            'tables_read' => $this->json($session->tablesRead === [] ? null : $session->tablesRead),
            'notes' => $this->json($session->notes === [] ? null : $session->notes),
            'truncated' => $session->truncated,
            'laravel_version' => $this->app->version(),
            'environment' => $this->app->environment(),
            'mode' => $session->trigger,
            'parent_trace_id' => $session->parentTraceId,
            'created_at' => Carbon::createFromTimestamp((int) floor($session->clock->wallStart)),
        ];

        $writes = array_map(static fn (array $w): array => array_intersect_key($w, array_flip(
            ['span_id', 'connection', 'table', 'operation', 'row_key', 'before', 'after', 'transaction', 'rows'],
        )), $session->writes);

        return new TraceData($trace, $spans, $session->lines, array_values($writes), $session->problems);
    }

    /** @return list<array<string, mixed>>|null */
    private function tablesWritten(TraceSession $session): ?array
    {
        $tables = [];
        foreach ($session->writes as $w) {
            $key = $w['connection'].'|'.$w['table'];
            $tables[$key] ??= ['connection' => $w['connection'], 'table' => $w['table'], 'insert' => 0, 'update' => 0, 'delete' => 0];
            $tables[$key][$w['operation']] += max(1, (int) ($w['rows'] ?? 1));
        }

        return $tables === [] ? null : array_values($tables);
    }

    private function userId(mixed $resolved): int|string|null
    {
        if (is_int($resolved) || is_string($resolved)) {
            return $resolved;
        }

        return Failure::guard(function () {
            $manager = Auth::getFacadeRoot();
            if (is_object($manager) && method_exists($manager, 'hasResolvedGuards') && ! $manager->hasResolvedGuards()) {
                return null;
            }
            $id = Auth::id();

            return is_int($id) || is_string($id) ? $id : null;
        }, 'resolving user id');
    }

    private function json(mixed $value): ?string
    {
        return $value === null ? null : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function clip(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max) : $value;
    }
}
