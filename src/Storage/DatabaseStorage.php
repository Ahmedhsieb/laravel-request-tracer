<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Storage;

use Ahmedhsieb\RequestTracer\Span;
use Ahmedhsieb\RequestTracer\TraceData;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class DatabaseStorage implements TraceStorage
{
    public function __construct(
        private readonly ConnectionResolver $connections,
        private readonly PayloadCodec $codec,
        private readonly string $prefix = 'tracer_',
        private readonly int $chunk = 500,
        /** an INSERT is split once the values in it pass this many bytes (the database's max_allowed_packet), whatever `chunk` says */
        private readonly int $maxBytes = 3_145_728,
    ) {}

    public function store(TraceData $data): void
    {
        try {
            $this->write($data);
        } catch (QueryException $e) {
            // the tables are missing on a fresh install: create them once and try again
            if (! TableInstaller::ensure()) {
                throw $e;
            }
            $this->write($data);
        }
    }

    private function write(TraceData $data): void
    {
        $db = $this->connections->resolve();

        $db->transaction(function () use ($db, $data): void {
            $trace = $data->trace;
            if ($trace['created_at'] instanceof DateTimeInterface) {
                $trace['created_at'] = self::utc($trace['created_at']);
            }
            $db->table($this->t('traces'))->insert($trace);

            $traceId = $data->id();
            $this->insertChunked($db, 'spans', array_map(fn (Span $s): array => $this->spanRow($traceId, $s), $data->spans));
            $this->insertChunked($db, 'lines', array_map(fn (array $l): array => ['trace_id' => $traceId] + $this->encodeLine($l), $data->lines));
            $this->insertChunked($db, 'writes', array_map(fn (array $w): array => ['trace_id' => $traceId] + $this->encodeWrite($w), $data->writes));
            $this->insertChunked($db, 'problems', array_map(fn (array $p): array => ['trace_id' => $traceId] + $p, $data->problems));
        });
    }

    public function trace(string $id): ?array
    {
        $row = $this->table('traces')->where('id', $id)->first();

        return $row === null ? null : $this->presentTrace((array) $row);
    }

    public function traces(array $filters = [], ?string $cursor = null, int $limit = 50): array
    {
        try {
            return $this->listTraces($filters, $cursor, $limit);
        } catch (QueryException $e) {
            // the dashboard is the first thing a new user opens: create the tables instead of failing
            if (! TableInstaller::ensure()) {
                throw $e;
            }

            return $this->listTraces($filters, $cursor, $limit);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function listTraces(array $filters, ?string $cursor, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $query = $this->table('traces')->orderByDesc('id');

        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '<', $cursor);
        }
        $this->applyFilters($query, $filters);

        $rows = $query->limit($limit + 1)->get()->values()->map(fn ($r): array => $this->presentTrace((array) $r))->all();
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (string) $rows[count($rows) - 1]['id'];
        }

        $kinds = $this->problemKinds(array_values(array_map(static fn (array $r): string => (string) $r['id'], $rows)));
        foreach ($rows as $i => $row) {
            $rows[$i]['problem_kinds'] = $kinds[$row['id']] ?? [];
        }

        return ['data' => array_values($rows), 'next_cursor' => $next];
    }

    /**
     * @param  list<string>  $traceIds
     * @return array<string, list<string>> trace id => distinct problem kinds
     */
    private function problemKinds(array $traceIds): array
    {
        if ($traceIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->table('problems')->whereIn('trace_id', $traceIds)->select(['trace_id', 'kind'])->distinct()->get() as $row) {
            $out[(string) $row->trace_id][] = (string) $row->kind;
        }

        return $out;
    }

    public function spans(string $traceId): array
    {
        $rows = $this->table('spans')
            ->where('trace_id', $traceId)
            ->orderBy('id')
            ->select(['id', 'parent_id', 'type', 'label', 'file', 'line', 'start_us', 'duration_us', 'memory_delta', 'flag', 'connection', 'count', 'meta'])
            ->selectRaw('CASE WHEN input IS NULL THEN 0 ELSE 1 END AS has_input')
            ->selectRaw('CASE WHEN output IS NULL THEN 0 ELSE 1 END AS has_output')
            ->get();

        return array_values($rows->map(function ($r): array {
            $r = (array) $r;

            return [
                'id' => (int) $r['id'],
                'parent_id' => $r['parent_id'] === null ? null : (int) $r['parent_id'],
                'type' => $r['type'],
                'label' => $r['label'],
                'file' => $r['file'],
                'line' => $r['line'] === null ? null : (int) $r['line'],
                'start_us' => (int) $r['start_us'],
                'duration_us' => (int) $r['duration_us'],
                'memory_delta' => (int) $r['memory_delta'],
                'flag' => $r['flag'],
                'connection' => $r['connection'],
                'count' => (int) $r['count'],
                'meta' => $r['meta'] === null ? null : json_decode((string) $r['meta'], true),
                'has_input' => (bool) $r['has_input'],
                'has_output' => (bool) $r['has_output'],
            ];
        })->all());
    }

    public function payload(string $traceId, int $spanId): ?array
    {
        $row = $this->table('spans')->where('trace_id', $traceId)->where('id', $spanId)->first(['input', 'output']);
        if ($row === null) {
            return null;
        }

        return [
            'input' => $this->codec->decode($row->input),
            'output' => $this->codec->decode($row->output),
        ];
    }

    public function lines(string $traceId, ?int $spanId = null): array
    {
        $query = $this->table('lines')->where('trace_id', $traceId)->orderBy('file')->orderBy('line')->orderBy('id');
        if ($spanId !== null) {
            $query->where('span_id', $spanId);
        }

        return array_values($query->get()->map(fn ($r): array => [
            'span_id' => $r->span_id === null ? null : (int) $r->span_id,
            'file' => $r->file,
            'line' => (int) $r->line,
            'kind' => $r->kind,
            'hits' => (int) $r->hits,
            'result' => $this->codec->decodeValue($r->result),
            'snippet' => $r->snippet,
        ])->all());
    }

    public function writes(string $traceId): array
    {
        return array_values($this->table('writes')->where('trace_id', $traceId)->orderBy('id')->get()->map(fn ($r): array => [
            'id' => (int) $r->id,
            'span_id' => $r->span_id === null ? null : (int) $r->span_id,
            'connection' => $r->connection,
            'table' => $r->table,
            'operation' => $r->operation,
            'row_key' => $r->row_key,
            'before' => $this->codec->decodeValue($r->before),
            'after' => $this->codec->decodeValue($r->after),
            'transaction' => $r->transaction,
            'rows' => $r->rows === null ? null : (int) $r->rows,
        ])->all());
    }

    public function problems(string $traceId): array
    {
        return array_values($this->table('problems')->where('trace_id', $traceId)->orderBy('id')->get()->map(fn ($r): array => [
            'id' => (int) $r->id,
            'span_id' => $r->span_id === null ? null : (int) $r->span_id,
            'kind' => $r->kind,
            'message' => $r->message,
            'file' => $r->file,
            'line' => $r->line === null ? null : (int) $r->line,
        ])->all());
    }

    public function prune(DateTimeInterface $olderThan, int $maxRows): int
    {
        $deleted = 0;

        while (true) {
            $ids = $this->idList($this->table('traces')->where('created_at', '<', self::utc($olderThan))->orderBy('id')->limit($this->chunk)->pluck('id'));
            if ($ids === []) {
                break;
            }
            $deleted += $this->deleteTraces($ids);
        }

        if ($maxRows > 0) {
            $cutoff = $this->table('traces')->orderByDesc('id')->skip($maxRows)->take(1)->value('id');
            if ($cutoff !== null) {
                while (true) {
                    $ids = $this->idList($this->table('traces')->where('id', '<=', $cutoff)->orderBy('id')->limit($this->chunk)->pluck('id'));
                    if ($ids === []) {
                        break;
                    }
                    $deleted += $this->deleteTraces($ids);
                }
            }
        }

        return $deleted;
    }

    public function count(): int
    {
        return (int) $this->table('traces')->count();
    }

    /**
     * @param  Collection<array-key, mixed>  $ids
     * @return list<string>
     */
    private function idList(Collection $ids): array
    {
        return array_values(array_map(static fn ($id): string => (string) $id, $ids->all()));
    }

    public function delete(string $id): bool
    {
        return $this->deleteTraces([$id]) > 0;
    }

    public function clear(): int
    {
        $deleted = 0;
        while (true) {
            $ids = $this->idList($this->table('traces')->orderBy('id')->limit($this->chunk)->pluck('id'));
            if ($ids === []) {
                return $deleted;
            }
            $deleted += $this->deleteTraces($ids);
        }
    }

    /** @param  list<string>  $ids */
    private function deleteTraces(array $ids): int
    {
        $db = $this->connections->resolve();

        return (int) $db->transaction(function () use ($db, $ids): int {
            foreach (['problems', 'writes', 'lines', 'spans'] as $child) {
                $db->table($this->t($child))->whereIn('trace_id', $ids)->delete();
            }

            return (int) $db->table($this->t('traces'))->whereIn('id', $ids)->delete();
        });
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = isset($filters['search']) ? trim((string) $filters['search']) : '';
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $spans = $this->t('spans');
            $traces = $this->t('traces');
            $query->where(function (Builder $q) use ($like, $spans, $traces, $filters): void {
                $q->where('path', 'like', $like)
                    ->orWhere('route_name', 'like', $like)
                    ->orWhere('id', 'like', $like);
                if (($filters['deep_search'] ?? false) === true) {
                    $q->orWhereExists(function (Builder $s) use ($like, $spans, $traces): void {
                        $s->from($spans)->selectRaw('1')->whereColumn($spans.'.trace_id', $traces.'.id')->where($spans.'.label', 'like', $like);
                    });
                }
            });
        }

        foreach (['user_id', 'tenant', 'module', 'kind', 'method', 'parent_trace_id', 'route_name'] as $column) {
            if (isset($filters[$column]) && $filters[$column] !== '') {
                $query->where($column, (string) $filters[$column]);
            }
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = (string) $filters['status'];
            if (preg_match('/^([1-5])xx$/i', $status, $m) === 1) {
                $query->whereBetween('status', [(int) $m[1] * 100, (int) $m[1] * 100 + 99]);
            } else {
                $query->where('status', (int) $status);
            }
        }
        if (isset($filters['min_ms']) && $filters['min_ms'] !== '') {
            $query->where('duration_ms', '>=', (float) $filters['min_ms']);
        }
        if (isset($filters['max_ms']) && $filters['max_ms'] !== '') {
            $query->where('duration_ms', '<=', (float) $filters['max_ms']);
        }
        if (! empty($filters['slow'])) {
            $query->where('duration_ms', '>=', (float) ($filters['slow_ms'] ?? 500));
        }
        if (! empty($filters['has_problems'])) {
            $query->where('problems_count', '>', 0);
        }
        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', self::utc($filters['from']));
        }
        if (! empty($filters['to'])) {
            $query->where('created_at', '<=', self::utc($filters['to']));
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function presentTrace(array $row): array
    {
        foreach (['tables_written', 'tables_read', 'notes'] as $json) {
            $row[$json] = isset($row[$json]) ? json_decode((string) $row[$json], true) : null;
        }
        foreach (['status', 'spans_count', 'queries_count', 'problems_count'] as $int) {
            $row[$int] = $row[$int] === null ? null : (int) $row[$int];
        }
        $row['duration_ms'] = (float) $row['duration_ms'];
        $row['memory_peak'] = (int) $row['memory_peak'];
        $row['truncated'] = (bool) $row['truncated'];

        return $row;
    }

    /** @return array<string, mixed> */
    private function spanRow(string $traceId, Span $s): array
    {
        return [
            'trace_id' => $traceId,
            'id' => $s->id,
            'parent_id' => $s->parentId,
            'type' => $s->type->value,
            'label' => $s->label,
            'file' => $s->file,
            'line' => $s->line,
            'start_us' => $s->startUs,
            'duration_us' => $s->durationUs,
            'memory_delta' => $s->memoryDelta,
            'flag' => $s->flag?->value,
            'connection' => $s->connection,
            'count' => $s->count,
            'meta' => $s->meta === [] ? null : (string) json_encode($s->meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'input' => $this->codec->encode($s->input),
            'output' => $this->codec->encode($s->output),
        ];
    }

    /**
     * @param  array<string, mixed>  $l
     * @return array<string, mixed>
     */
    private function encodeLine(array $l): array
    {
        $l['result'] = $this->codec->encode($l['result'] ?? null);

        return $l;
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function encodeWrite(array $w): array
    {
        $w['before'] = $this->codec->encode($w['before'] ?? null);
        $w['after'] = $this->codec->encode($w['after'] ?? null);

        return $w;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function insertChunked(Connection $db, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $columns = max(1, count($rows[0]));
        $maxParams = match ($db->getDriverName()) {
            'sqlsrv' => 2000,
            'sqlite' => 900,
            default => 60000,
        };
        $size = max(1, min($this->chunk, intdiv($maxParams, $columns)));

        $batch = [];
        $bytes = 0;
        foreach ($rows as $row) {
            $rowBytes = 0;
            foreach ($row as $value) {
                $rowBytes += is_string($value) ? strlen($value) : 16;
            }
            // a row is never split: one bigger than the limit goes alone
            if ($batch !== [] && (count($batch) >= $size || $bytes + $rowBytes > $this->maxBytes)) {
                $db->table($this->t($table))->insert($batch);
                $batch = [];
                $bytes = 0;
            }
            $batch[] = $row;
            $bytes += $rowBytes;
        }
        $db->table($this->t($table))->insert($batch);                // the last one: $rows is not empty, so neither is the batch
    }

    private function table(string $name): Builder
    {
        return $this->connections->resolve()->table($this->t($name));
    }

    private function t(string $name): string
    {
        return $this->prefix.$name;
    }

    /** `created_at` is stored in UTC; anything compared with it is converted first. */
    private static function utc(mixed $moment): string
    {
        try {
            return Carbon::parse($moment instanceof DateTimeInterface ? Carbon::instance($moment) : (string) $moment)->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return '0001-01-01 00:00:00';
        }
    }
}
