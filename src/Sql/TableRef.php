<?php

declare(strict_types=1);

namespace RequestTracer\Sql;

/** A table mentioned in a statement. `operation` is only set for tables the statement writes to. */
final class TableRef
{
    /**
     * @param  list<string>  $columns  columns written (INSERT column list / UPDATE SET targets)
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $schema = null,
        public readonly ?string $alias = null,
        public ?string $operation = null,
        public ?int $rows = null,
        public array $columns = [],
    ) {}

    /** Name as it was written, schema-qualified when the SQL qualified it. */
    public function qualified(): string
    {
        return $this->schema !== null && $this->schema !== '' ? $this->schema.'.'.$this->name : $this->name;
    }

    /** Same table, ignoring schema and the connection's table prefix; used to match query writes with model events. */
    public function matches(string $table, string $prefix = ''): bool
    {
        $name = $this->name;
        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }
        $other = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        return strcasecmp($name, $other) === 0 || strcasecmp($this->name, $other) === 0;
    }
}
