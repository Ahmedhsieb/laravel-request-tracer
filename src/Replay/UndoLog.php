<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Replay;

use Ahmedhsieb\RequestTracer\Sql\SqlAnalyzer;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * What a replay that ran *for real* changed, so that it can be taken back.
 *
 * The trace's own write list cannot do this: its values are masked and cut, query-builder writes have no "before", and hidden
 * attributes are left out. This log is separate and exact. While a real replay runs it records every Eloquent write with the raw
 * values (the row for an insert, the changed columns before and after for an update, the whole row for a delete) and counts every other
 * write statement (query builder, raw SQL) so that it can say honestly what it cannot undo.
 *
 * Because the values are raw (that is the point) the log is never written in clear: it is encrypted with the application key, kept in
 * `storage/framework/request-tracer/undo/<trace id>.undo`, never shown, and deleted once undone or after `replay.undo_hours`.
 * Undoing is all or nothing and refuses a row that changed since the replay, unless it is forced.
 */
final class UndoLog
{
    private static bool $capturing = false;

    /** @var \WeakReference<object>|null the event dispatcher the listeners were registered on */
    private static ?\WeakReference $registeredOn = null;

    /** @var list<array<string, mixed>> */
    private static array $entries = [];

    /** @var array<string, int> table => write statements seen */
    private static array $statements = [];

    /** @var array<string, int> table => writes recorded in */
    private static array $covered = [];

    public static function begin(): void
    {
        self::$entries = [];
        self::$statements = [];
        self::$covered = [];
        self::$capturing = true;

        $dispatcher = Event::getFacadeRoot();
        if (is_object($dispatcher) && self::$registeredOn?->get() !== $dispatcher) {
            self::$registeredOn = \WeakReference::create($dispatcher);
            Event::listen('eloquent.*', static function (string $event, array $payload): void {
                if (! self::$capturing) {
                    return;
                }
                try {
                    self::capture($event, $payload[0] ?? null);
                } catch (Throwable $e) {
                    Failure::report($e, 'undo log');
                }
            });
            Event::listen(QueryExecuted::class, static function (QueryExecuted $query): void {
                if (! self::$capturing) {
                    return;
                }
                try {
                    self::countStatement($query);
                } catch (Throwable $e) {
                    Failure::report($e, 'undo log');
                }
            });
        }
    }

    /**
     * Stop recording. `uncovered` are the tables that were written by statements the log has no exact copy of.
     *
     * @return array{entries: list<array<string, mixed>>, uncovered: array<string, int>}
     */
    public static function finish(): array
    {
        self::$capturing = false;
        $uncovered = [];
        foreach (self::$statements as $table => $count) {
            $missing = $count - (self::$covered[$table] ?? 0);
            if ($missing > 0) {
                $uncovered[$table] = $missing;
            }
        }
        $entries = self::$entries;
        self::$entries = [];
        self::$statements = [];
        self::$covered = [];

        return ['entries' => $entries, 'uncovered' => $uncovered];
    }

    /**
     * Keep the log of a finished replay.
     *
     * @param  array{entries: list<array<string, mixed>>, uncovered: array<string, int>}  $log
     * @return array<string, mixed>|null what summary() says about it, null when there is nothing to undo or to say
     */
    public static function save(string $traceId, array $log): ?array
    {
        if (! self::validId($traceId) || ($log['entries'] === [] && $log['uncovered'] === [])) {
            return null;
        }
        $payload = json_encode(['created_at' => time(), 'entries' => self::packAll($log['entries']), 'uncovered' => $log['uncovered']], JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            return null;
        }
        $directory = self::directory();
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return null;
        }
        if (@file_put_contents(self::file($traceId), Crypt::encryptString($payload)) === false) {
            return null;
        }

        return self::summary($traceId);
    }

    /**
     * What can still be undone for a trace, without any value in it.
     *
     * @return array{available: bool, entries: int, tables: array<string, array<string, int>>, uncovered: array<string, int>, expires_at: string|null}|null
     */
    public static function summary(string $traceId): ?array
    {
        $log = self::read($traceId);
        if ($log === null) {
            return null;
        }
        $tables = [];
        foreach ($log['entries'] as $entry) {
            $table = (string) $entry['table'];
            $op = (string) $entry['op'];
            $tables[$table][$op] = ($tables[$table][$op] ?? 0) + 1;
        }
        $hours = (float) App::config()->get('request-tracer.replay.undo_hours', 24);

        return [
            'available' => $log['entries'] !== [],
            'entries' => count($log['entries']),
            'tables' => $tables,
            'uncovered' => $log['uncovered'],
            'expires_at' => gmdate('c', (int) ($log['created_at'] + $hours * 3600)),
        ];
    }

    /**
     * Take the recorded changes back, last first. Nothing is changed when a row differs from what the replay left (unless `$force`).
     *
     * @return array{applied: bool, undone: int, conflicts: list<array{table: string, key: string, operation: string, reason: string}>, error: string|null}
     */
    public static function apply(string $traceId, bool $force = false): array
    {
        $log = self::read($traceId);
        if ($log === null || $log['entries'] === []) {
            return ['applied' => false, 'undone' => 0, 'conflicts' => [], 'error' => 'There is nothing to undo for this trace (it was not run for real, the log expired, or it was undone already).'];
        }

        // Last change first, inside a transaction per connection: each row is judged as the changes after it were just taken back, so a
        // row that the replay itself changed twice is not a conflict, and nothing is kept unless every row can be undone (or it is forced).
        /** @var array<string, Connection> $open */
        $open = [];
        $undone = 0;
        $conflicts = [];
        try {
            foreach (array_reverse($log['entries']) as $entry) {
                $name = (string) self::connectionName($entry);
                $connection = $open[$name] ?? null;
                if ($connection === null) {
                    $connection = DB::connection($name === '' ? null : $name);
                    $connection->beginTransaction();
                    $open[$name] = $connection;
                }
                $reason = self::conflict($connection, $entry);
                if ($reason !== null) {
                    $conflicts[] = ['table' => (string) $entry['table'], 'key' => (string) $entry['key'], 'operation' => (string) $entry['op'], 'reason' => $reason];
                    if (! $force || $reason === 'a row with this key exists again' || $reason === 'the row is gone') {
                        continue;
                    }
                }
                $undone += self::undo($connection, $entry) ? 1 : 0;
            }
            if ($conflicts !== [] && ! $force) {
                self::rollBackAll($open);

                return ['applied' => false, 'undone' => 0, 'conflicts' => $conflicts, 'error' => null];
            }
            foreach ($open as $connection) {
                $connection->commit();
            }
        } catch (Throwable $e) {
            self::rollBackAll($open);

            return ['applied' => false, 'undone' => 0, 'conflicts' => $conflicts, 'error' => 'The undo was cancelled, nothing was changed: '.$e->getMessage()];
        }

        @unlink(self::file($traceId));

        return ['applied' => true, 'undone' => $undone, 'conflicts' => $conflicts, 'error' => null];
    }

    /** @param  array<string, Connection>  $open */
    private static function rollBackAll(array $open): void
    {
        foreach ($open as $connection) {
            try {
                $connection->rollBack();
            } catch (Throwable) {
                // a connection that went away has nothing left to roll back
            }
        }
    }

    public static function forget(string $traceId): void
    {
        if (self::validId($traceId)) {
            @unlink(self::file($traceId));
        }
    }

    /** Delete the logs older than `replay.undo_hours`. */
    public static function prune(): int
    {
        $limit = time() - (int) ((float) App::config()->get('request-tracer.replay.undo_hours', 24) * 3600);
        $deleted = 0;
        clearstatcache();                                   // another process wrote these files: PHP may remember an older timestamp
        foreach (glob(self::directory().'/*.undo') ?: [] as $file) {
            if ((int) @filemtime($file) < $limit && @unlink($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ------------------------------------------------------------------ capture

    private static function capture(string $event, mixed $model): void
    {
        if (! $model instanceof Model || ! str_starts_with($event, 'eloquent.')) {
            return;
        }
        $colon = strpos($event, ':');
        $kind = $colon === false ? '' : substr($event, 9, $colon - 9);
        if (! in_array($kind, ['created', 'updated', 'deleted', 'forceDeleted'], true)) {
            return;
        }

        $key = $model->getKey();
        if (! is_scalar($key) || $model->getKeyName() === '') {
            return;                                         // no single key to find the row by: the statement stays uncovered
        }

        $entry = ['connection' => $model->getConnectionName(), 'table' => $model->getTable(), 'key_name' => $model->getKeyName(), 'key' => $key, 'before' => null, 'after' => null];

        if ($kind === 'created') {
            $entry += ['op' => 'insert'];
            $entry['after'] = $model->getAttributes();
        } elseif ($kind === 'updated') {
            $changes = $model->getChanges();
            if ($changes === []) {
                return;
            }
            $entry += ['op' => 'update'];
            $entry['before'] = array_intersect_key($model->getRawOriginal(), $changes);
            $entry['after'] = $changes;
        } elseif ($kind === 'deleted' && method_exists($model, 'trashed') && method_exists($model, 'isForceDeleting') && method_exists($model, 'getDeletedAtColumn') && ! $model->isForceDeleting() && $model->trashed()) {
            // a soft delete only sets the "deleted at" column
            $column = (string) $model->getDeletedAtColumn();
            $entry += ['op' => 'update'];
            $entry['before'] = [$column => null];
            $entry['after'] = [$column => $model->getAttributes()[$column] ?? null];
        } else {
            $previous = self::$entries[count(self::$entries) - 1] ?? null;
            if ($kind === 'forceDeleted' && $previous !== null && $previous['op'] === 'delete' && $previous['table'] === $entry['table'] && $previous['key'] === $key) {
                return;                                     // a force delete fires "deleted" and then "forceDeleted" for one statement
            }
            $entry += ['op' => 'delete'];
            $entry['before'] = $model->getRawOriginal() ?: $model->getAttributes();
        }

        self::$entries[] = $entry;
        $table = self::tableKey((string) $entry['table'], '');
        self::$covered[$table] = (self::$covered[$table] ?? 0) + 1;
    }

    private static function countStatement(QueryExecuted $query): void
    {
        if (str_starts_with((string) $query->connectionName, 'request-tracer-writer')) {
            return;
        }
        $statement = SqlAnalyzer::analyze($query->sql, $query->connection->getDriverName());
        if (! $statement->isWrite()) {
            return;
        }
        $prefix = $query->connection->getTablePrefix();
        $own = self::ignoredTables();
        foreach ($statement->writes as $table) {
            $name = self::tableKey($table->name, $prefix);
            if (isset($own[$name]) || str_starts_with($name, 'request_tracer_')) {
                continue;
            }
            self::$statements[$name] = (self::$statements[$name] ?? 0) + 1;
        }
    }

    /** @return array<string, true> tables whose writes are the framework's bookkeeping, not the application's data */
    private static function ignoredTables(): array
    {
        $config = App::config();
        $names = [$config->get('session.table', 'sessions'), $config->get('cache.stores.database.table', 'cache'), $config->get('cache.stores.database.lock_table', 'cache_locks'), $config->get('request-tracer.storage.table_prefix', 'request_tracer_').'traces'];
        $out = [];
        foreach ($names as $name) {
            if (is_string($name) && $name !== '') {
                $out[self::tableKey($name, '')] = true;
            }
        }

        return $out;
    }

    private static function tableKey(string $table, string $prefix): string
    {
        $table = strtolower(trim($table, '`"[] '));
        if (str_contains($table, '.')) {
            $table = substr($table, (int) strrpos($table, '.') + 1);
        }
        $table = trim($table, '`"[] ');
        $prefix = strtolower($prefix);

        return $prefix !== '' && str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
    }

    // ------------------------------------------------------------------ undo

    /** @param  array<string, mixed>  $entry */
    private static function conflict(Connection $connection, array $entry): ?string
    {
        $row = self::current($connection, $entry);
        $op = (string) $entry['op'];
        if ($op === 'delete') {
            return $row !== null ? 'a row with this key exists again' : null;
        }
        if ($row === null) {
            return 'the row is gone';
        }
        foreach ((array) $entry['after'] as $column => $expected) {
            if (! array_key_exists($column, $row)) {
                continue;
            }
            if (! self::same($row[$column], $expected)) {
                return 'the column "'.$column.'" was changed after the replay';
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $entry */
    private static function undo(Connection $connection, array $entry): bool
    {
        $query = $connection->table((string) $entry['table'])->where((string) $entry['key_name'], $entry['key']);
        $op = (string) $entry['op'];
        if ($op === 'insert') {
            return $query->delete() > 0;
        }
        if ($op === 'update') {
            return $query->update((array) $entry['before']) > 0;
        }

        return $connection->table((string) $entry['table'])->insert((array) $entry['before']);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    private static function current(Connection $connection, array $entry): ?array
    {
        $row = $connection->table((string) $entry['table'])->where((string) $entry['key_name'], $entry['key'])->first();

        return $row === null ? null : (array) $row;
    }

    /** @param  array<string, mixed>  $entry */
    private static function connectionName(array $entry): ?string
    {
        return is_string($entry['connection'] ?? null) && $entry['connection'] !== '' ? $entry['connection'] : null;
    }

    /** Equal the way a database returns a value and a model holds it: "10.5" and 10.50, true and 1, null and null. */
    private static function same(mixed $a, mixed $b): bool
    {
        $a = is_bool($a) ? (int) $a : $a;
        $b = is_bool($b) ? (int) $b : $b;
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) (is_scalar($a) ? $a : json_encode($a)) === (string) (is_scalar($b) ? $b : json_encode($b));
    }

    // ------------------------------------------------------------------ storage

    private static function directory(): string
    {
        $configured = App::config()->get('request-tracer.replay.undo_path');

        return is_string($configured) && $configured !== '' ? rtrim($configured, '/\\') : App::application()->storagePath('framework/request-tracer/undo');
    }

    private static function file(string $traceId): string
    {
        return self::directory().'/'.$traceId.'.undo';
    }

    private static function validId(string $traceId): bool
    {
        return preg_match('/^[0-9A-Za-z]{26}$/', $traceId) === 1;
    }

    /** @return array{created_at: int, entries: list<array<string, mixed>>, uncovered: array<string, int>}|null */
    private static function read(string $traceId): ?array
    {
        if (! self::validId($traceId) || ! is_file(self::file($traceId))) {
            return null;
        }
        try {
            $decoded = json_decode(Crypt::decryptString((string) file_get_contents(self::file($traceId))), true);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($decoded) || ! is_array($decoded['entries'] ?? null)) {
            return null;
        }
        $created = (int) ($decoded['created_at'] ?? 0);
        $hours = (float) App::config()->get('request-tracer.replay.undo_hours', 24);
        if ($created + $hours * 3600 < time()) {
            @unlink(self::file($traceId));

            return null;
        }

        return [
            'created_at' => $created,
            'entries' => self::unpackAll(array_values(array_filter($decoded['entries'], 'is_array'))),
            'uncovered' => array_map('intval', (array) ($decoded['uncovered'] ?? [])),
        ];
    }

    /**
     * Values that are not valid UTF-8 (a binary column) survive JSON as base64.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function packAll(array $entries): array
    {
        return array_map(static function (array $entry): array {
            foreach (['before', 'after'] as $side) {
                if (is_array($entry[$side])) {
                    $entry[$side] = array_map(static fn (mixed $v): mixed => is_string($v) && ! mb_check_encoding($v, 'UTF-8') ? ['__b64' => base64_encode($v)] : $v, $entry[$side]);
                }
            }

            return $entry;
        }, $entries);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private static function unpackAll(array $entries): array
    {
        return array_map(static function (array $entry): array {
            foreach (['before', 'after'] as $side) {
                if (is_array($entry[$side] ?? null)) {
                    $entry[$side] = array_map(static fn (mixed $v): mixed => is_array($v) && isset($v['__b64']) ? base64_decode((string) $v['__b64']) : $v, $entry[$side]);
                }
            }

            return $entry;
        }, $entries);
    }
}
