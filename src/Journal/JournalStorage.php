<?php

declare(strict_types=1);

namespace RequestTracer\Journal;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RequestTracer\Storage\ConnectionResolver;
use RequestTracer\Storage\TableInstaller;
use RequestTracer\Support\App;

/** The journal's table: insert one row per request, list, find, trim. Reads and writes use the tracer's own connection. */
class JournalStorage
{
    public function __construct(private readonly ConnectionResolver $connections) {}

    /**
     * @param  array<string, mixed>  $row  method, url, route_name, status, duration_ms, user_id, failure, headers (array), body, body_cut, masked, trace_id
     */
    public function store(array $row): string
    {
        $id = (string) Str::ulid();
        $values = [
            'id' => $id,
            'method' => substr((string) $row['method'], 0, 10),
            'url' => substr((string) $row['url'], 0, 1024),
            'route_name' => isset($row['route_name']) ? substr((string) $row['route_name'], 0, 255) : null,
            'status' => $row['status'] ?? null,
            'duration_ms' => round((float) ($row['duration_ms'] ?? 0), 2),
            'user_id' => isset($row['user_id']) ? substr((string) $row['user_id'], 0, 64) : null,
            'failure' => isset($row['failure']) ? mb_substr((string) $row['failure'], 0, 500) : null,
            'headers' => empty($row['headers']) ? null : (string) json_encode($row['headers'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'body' => $row['body'] ?? null,
            'body_cut' => (bool) ($row['body_cut'] ?? false),
            'masked' => (bool) ($row['masked'] ?? false),
            'trace_id' => $row['trace_id'] ?? null,
            'created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ];

        try {
            $this->table()->insert($values);
        } catch (QueryException $e) {
            if (! TableInstaller::ensureJournal()) {
                throw $e;
            }
            $this->table()->insert($values);
        }

        return $id;
    }

    /**
     * @param  array{status?: string, search?: string}  $filters  status: `failed` (5xx or an exception), `errors` (4xx and above), or a status code
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(array $filters = [], ?string $cursor = null, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        try {
            return $this->query($filters, $cursor, $limit);
        } catch (QueryException $e) {
            if (! TableInstaller::ensureJournal()) {
                throw $e;
            }

            return $this->query($filters, $cursor, $limit);
        }
    }

    /** @return array<string, mixed>|null the whole row, with headers decoded */
    public function find(string $id): ?array
    {
        $row = $this->table()->where('id', $id)->first();

        return $row === null ? null : $this->present((array) $row, true);
    }

    /**
     * The newest entry (optionally the newest failed one).
     *
     * @return array<string, mixed>|null
     */
    public function latest(bool $failedOnly = false): ?array
    {
        $query = $this->table()->orderByDesc('id');
        if ($failedOnly) {
            $this->failed($query);
        }
        $row = $query->first();

        return $row === null ? null : $this->present((array) $row, true);
    }

    public function count(): int
    {
        return (int) $this->table()->count();
    }

    public function clear(): int
    {
        return (int) $this->table()->delete();
    }

    /** Keep the journal small: drop what is older than `$hours` and everything beyond the newest `$maxRows`. @return int rows deleted */
    public function trim(float $hours, int $maxRows): int
    {
        $deleted = (int) $this->table()->where('created_at', '<', Carbon::now('UTC')->subSeconds((int) round($hours * 3600))->format('Y-m-d H:i:s'))->delete();
        if ($maxRows > 0) {
            $cutoff = $this->table()->orderByDesc('id')->skip($maxRows)->take(1)->value('id');
            if (is_string($cutoff)) {
                $deleted += (int) $this->table()->where('id', '<=', $cutoff)->delete();
            }
        }

        return $deleted;
    }

    /**
     * @param  array{status?: string, search?: string}  $filters
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    private function query(array $filters, ?string $cursor, int $limit): array
    {
        $query = $this->table()->orderByDesc('id');
        if ($cursor !== null && $cursor !== '') {
            $query->where('id', '<', $cursor);
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'failed') {
            $this->failed($query);
        } elseif ($status === 'errors') {
            $query->where('status', '>=', 400);
        } elseif (ctype_digit($status)) {
            $query->where('status', (int) $status);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(static function (Builder $q) use ($like): void {
                $q->where('url', 'like', $like)->orWhere('user_id', 'like', $like)->orWhere('failure', 'like', $like)->orWhere('route_name', 'like', $like);
            });
        }

        $rows = array_values($query->limit($limit + 1)->get()->map(fn ($r): array => $this->present((array) $r, false))->all());
        $next = null;
        if (count($rows) > $limit) {
            array_pop($rows);
            $next = (string) $rows[count($rows) - 1]['id'];
        }

        return ['data' => $rows, 'next_cursor' => $next];
    }

    private function failed(Builder $query): void
    {
        $query->where(static function (Builder $q): void {
            $q->where('status', '>=', 500)->orWhereNotNull('failure');
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row, bool $full): array
    {
        $headers = is_string($row['headers'] ?? null) ? json_decode($row['headers'], true) : null;
        $out = [
            'id' => (string) $row['id'],
            'method' => (string) $row['method'],
            'url' => (string) $row['url'],
            'route_name' => $row['route_name'] ?? null,
            'status' => isset($row['status']) ? (int) $row['status'] : null,
            'duration_ms' => (float) $row['duration_ms'],
            'user_id' => $row['user_id'] ?? null,
            'failure' => $row['failure'] ?? null,
            'has_body' => ($row['body'] ?? null) !== null,
            'body_cut' => (bool) $row['body_cut'],
            'masked' => (bool) $row['masked'],
            'trace_id' => $row['trace_id'] ?? null,
            'created_at' => Carbon::parse((string) $row['created_at'], 'UTC')->toIso8601String(),
        ];
        if ($full) {
            $out['headers'] = is_array($headers) ? $headers : [];
            $out['body'] = $row['body'] ?? null;
        }

        return $out;
    }

    private function table(): Builder
    {
        return $this->connection()->table((string) App::config()->get('request-tracer.storage.table_prefix', 'tracer_').'journal');
    }

    private function connection(): Connection
    {
        return $this->connections->resolve();
    }
}
