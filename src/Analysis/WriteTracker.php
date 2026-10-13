<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Analysis;

use Ahmedhsieb\RequestTracer\Dumper\Masker;
use Ahmedhsieb\RequestTracer\Sql\TableRef;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Brand;
use Ahmedhsieb\RequestTracer\TraceSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Collects the rows a request wrote.
 *
 * Eloquent model events give complete rows (before/after); `QueryExecuted` covers query-builder and raw SQL by statement
 * type and table. One save() produces both, so a model event claims the query write that just happened for the same table
 * and upgrades it with the row data instead of adding a duplicate.
 */
final class WriteTracker
{
    private const MAX_VALUE_CHARS = 300;

    public function __construct(
        private readonly TraceSession $session,
        private readonly Masker $masker,
        private readonly int $maxWrites,
    ) {}

    /**
     * @param  list<string|null>  $names  column name of each binding (BindingNamer)
     * @param  list<mixed>  $bindings
     */
    public function fromQuery(string $connection, string $operation, TableRef $table, int $spanId, array $names, array $bindings, int $level, string $prefix, bool $upsert): void
    {
        if (count($this->session->writes) >= $this->maxWrites) {
            $this->session->truncated = true;

            return;
        }

        $assoc = $this->bindingsByColumn($table, $names, $bindings, $operation);
        $rowKey = $this->rowKey($names, $bindings);

        $this->session->writes[] = [
            'span_id' => $spanId,
            'connection' => $connection,
            'table' => $table->qualified(),
            'operation' => $operation,
            'row_key' => $rowKey,
            'before' => null,
            'after' => $assoc === null ? null : $this->json($assoc),
            'transaction' => $level > 0 ? 'open' : 'none',
            'rows' => $operation === 'insert' ? ($table->rows ?? 1) : null,
            'claimed' => false,
            'prefix' => $prefix,
            'upsert' => $upsert,
        ];
    }

    public function fromModel(string $kind, Model $model): void
    {
        $connection = (string) ($model->getConnectionName() ?? $this->defaultConnection());
        $table = $model->getTable();
        $hidden = array_flip(array_merge($model->getHidden(), []));

        if ($kind === 'created') {
            $operation = 'insert';
            $before = null;
            $after = $this->row($model->getAttributes(), $hidden);
        } elseif ($kind === 'updated') {
            $operation = 'update';
            $changes = $model->getChanges();
            $before = $this->row(array_intersect_key($model->getOriginal(), $changes), $hidden);
            $after = $this->row($changes, $hidden);
        } else {                                                    // deleted | forceDeleted
            $operation = 'delete';
            $before = $this->row($model->getOriginal() ?: $model->getAttributes(), $hidden);
            $after = null;
        }

        $key = $model->getKey();
        $rowKey = is_scalar($key) ? (string) $key : null;
        $tx = $this->session->bag['tx'][$connection]['level'] ?? 0;

        // claim the query write that this model event belongs to
        for ($i = count($this->session->writes) - 1; $i >= 0 && $i >= count($this->session->writes) - 25; $i--) {
            $w = $this->session->writes[$i];
            if ($w['claimed'] || $w['connection'] !== $connection) {
                continue;
            }
            $ref = new TableRef(str_contains((string) $w['table'], '.') ? substr((string) $w['table'], (int) strrpos((string) $w['table'], '.') + 1) : (string) $w['table']);
            $sameTable = $ref->matches($table, (string) ($w['prefix'] ?? ''));
            $compatible = $w['operation'] === $operation || ($operation === 'delete' && $w['operation'] === 'update');
            if ($sameTable && $compatible) {
                $this->session->writes[$i]['operation'] = $operation;
                $this->session->writes[$i]['row_key'] = $rowKey;
                $this->session->writes[$i]['before'] = $before === null ? null : $this->json($before);
                $this->session->writes[$i]['after'] = $after === null ? null : $this->json($after);
                $this->session->writes[$i]['rows'] = 1;
                $this->session->writes[$i]['claimed'] = true;

                return;
            }
        }

        if (count($this->session->writes) >= $this->maxWrites) {
            $this->session->truncated = true;

            return;
        }

        // no query seen (e.g. the query listener was off): keep the model-level write on its own
        $this->session->writes[] = [
            'span_id' => $this->session->recorder->currentId(),
            'connection' => $connection,
            'table' => $table,
            'operation' => $operation,
            'row_key' => $rowKey,
            'before' => $before === null ? null : $this->json($before),
            'after' => $after === null ? null : $this->json($after),
            'transaction' => $tx > 0 ? 'open' : 'none',
            'rows' => 1,
            'claimed' => true,
            'prefix' => '',
            'upsert' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $hidden
     * @return array<string, mixed>
     */
    private function row(array $attributes, array $hidden): array
    {
        $out = [];
        foreach ($attributes as $column => $value) {
            $column = (string) $column;
            $out[$column] = (isset($hidden[$column]) || $this->masker->isSensitiveKey($column)) ? Brand::MASK : $this->scalar($value);
        }

        return $out;
    }

    private function scalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_string($value)) {
            $value = $this->masker->maskString($value);

            return mb_strlen($value) > self::MAX_VALUE_CHARS ? mb_substr($value, 0, self::MAX_VALUE_CHARS).'…' : $value;
        }
        $json = json_encode($value, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = is_string($json) ? $this->masker->maskString($json) : '[unserializable]';

        return mb_strlen($json) > self::MAX_VALUE_CHARS ? mb_substr($json, 0, self::MAX_VALUE_CHARS).'…' : $json;
    }

    /**
     * Column => value for INSERT (first row) and UPDATE (assigned columns); null when the statement has no usable mapping.
     *
     * @param  list<string|null>  $names
     * @param  list<mixed>  $bindings
     * @return array<string, mixed>|null
     */
    private function bindingsByColumn(TableRef $table, array $names, array $bindings, string $operation): ?array
    {
        if ($operation === 'delete' || $names === [] || count($names) !== count($bindings)) {
            return null;
        }

        $columns = array_flip($table->columns);
        $out = [];
        foreach ($names as $i => $name) {
            if ($name === null || ! isset($columns[$name]) || array_key_exists($name, $out)) {
                continue;                                           // first row only; WHERE columns are not "written"
            }
            $out[$name] = $this->masker->isSensitiveKey($name) ? Brand::MASK : $this->scalar($bindings[$i]);
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  list<string|null>  $names
     * @param  list<mixed>  $bindings
     */
    private function rowKey(array $names, array $bindings): ?string
    {
        if (count($names) !== count($bindings)) {
            return null;
        }
        foreach ($names as $i => $name) {
            if ($name === 'id' && is_scalar($bindings[$i])) {
                return (string) $bindings[$i];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    private function json(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function defaultConnection(): string
    {
        return (string) App::config()->get('database.default', 'default');
    }
}
