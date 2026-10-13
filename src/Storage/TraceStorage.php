<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Storage;

use Ahmedhsieb\RequestTracer\TraceData;
use DateTimeInterface;

/**
 * Where finished traces live. The database driver is the only one shipped; register others under
 * `storage.drivers` in the config (see docs/11-extending.md).
 *
 * Write path: store(). Read path: everything else (used by the JSON API). Payloads (span input/output) are never
 * part of spans(); fetch them lazily with payload().
 */
interface TraceStorage
{
    public function store(TraceData $data): void;

    /** @return array<string, mixed>|null the trace summary row */
    public function trace(string $id): ?array;

    /**
     * @param  array<string, mixed>  $filters  search, status, min_ms, max_ms, slow_ms, has_problems, slow, user_id, tenant, module, kind, method, from, to, parent_trace_id
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function traces(array $filters = [], ?string $cursor = null, int $limit = 50): array;

    /** @return list<array<string, mixed>> span rows without payloads, ordered by id */
    public function spans(string $traceId): array;

    /** @return array{input: string|null, output: string|null}|null JSON texts (decompressed) */
    public function payload(string $traceId, int $spanId): ?array;

    /** @return list<array<string, mixed>> */
    public function lines(string $traceId, ?int $spanId = null): array;

    /** @return list<array<string, mixed>> */
    public function writes(string $traceId): array;

    /** @return list<array<string, mixed>> */
    public function problems(string $traceId): array;

    /** Delete traces older than $olderThan and keep at most $maxRows traces. Returns the number of traces deleted. */
    public function prune(DateTimeInterface $olderThan, int $maxRows): int;

    public function count(): int;

    /** Delete one trace with everything that belongs to it. Returns whether it existed. */
    public function delete(string $id): bool;

    /** Delete every trace. Returns the number of traces removed. */
    public function clear(): int;
}
