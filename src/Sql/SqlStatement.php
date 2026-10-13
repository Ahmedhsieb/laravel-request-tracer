<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Sql;

/** What the tracer knows about one SQL statement. */
final class SqlStatement
{
    /**
     * @param  string  $operation  select | insert | update | delete | truncate | merge | ddl | transaction | other
     * @param  list<TableRef>  $writes  tables the statement writes (operation = insert|update|delete)
     * @param  list<TableRef>  $reads  tables the statement reads
     * @param  string  $fingerprint  the statement with literals replaced by "?": identical shape = identical fingerprint
     */
    public function __construct(
        public readonly string $operation,
        public readonly array $writes,
        public readonly array $reads,
        public readonly string $fingerprint,
        public readonly bool $upsert = false,
    ) {}

    public function isWrite(): bool
    {
        return $this->writes !== [];
    }
}
