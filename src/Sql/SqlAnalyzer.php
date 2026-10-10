<?php

declare(strict_types=1);

namespace RequestTracer\Sql;

/**
 * Works out what a SQL statement does: its kind, the tables it writes and reads, and a fingerprint of its shape.
 *
 * Handles INSERT (IGNORE / OR REPLACE / ... SELECT / ON DUPLICATE KEY / ON CONFLICT), REPLACE, UPSERT, UPDATE (MySQL multi-table,
 * PostgreSQL FROM, SQL Server alias + FROM), DELETE (single, MySQL/SQL Server multi-target), TRUNCATE, MERGE and CTEs
 * (including PostgreSQL data-modifying CTEs), with quoted and schema-qualified names ("a"."b", `a`.`b`, [a].[b]).
 */
final class SqlAnalyzer
{
    /** Words that can never be a table alias. */
    private const NOT_ALIAS = [
        'ON' => 1, 'USING' => 1, 'WHERE' => 1, 'SET' => 1, 'JOIN' => 1, 'INNER' => 1, 'LEFT' => 1, 'RIGHT' => 1, 'FULL' => 1, 'CROSS' => 1,
        'NATURAL' => 1, 'OUTER' => 1, 'GROUP' => 1, 'ORDER' => 1, 'LIMIT' => 1, 'OFFSET' => 1, 'UNION' => 1, 'INTERSECT' => 1, 'EXCEPT' => 1,
        'VALUES' => 1, 'VALUE' => 1, 'SELECT' => 1, 'FROM' => 1, 'HAVING' => 1, 'WINDOW' => 1, 'FOR' => 1, 'RETURNING' => 1, 'OUTPUT' => 1,
        'FETCH' => 1, 'PARTITION' => 1, 'USE' => 1, 'FORCE' => 1, 'IGNORE' => 1, 'STRAIGHT_JOIN' => 1, 'TABLESAMPLE' => 1, 'WITH' => 1,
        'WHEN' => 1, 'THEN' => 1, 'LOCK' => 1, 'OPTION' => 1, 'DEFAULT' => 1, 'LATERAL' => 1, 'OVERRIDING' => 1, 'ONLY' => 1, 'AS' => 1,
    ];

    /** Functions whose argument list may contain a FROM that is not a table reference. */
    private const FROM_FUNCTIONS = ['EXTRACT' => 1, 'TRIM' => 1, 'SUBSTRING' => 1, 'SUBSTR' => 1, 'OVERLAY' => 1, 'POSITION' => 1];

    private const DDL = ['CREATE' => 1, 'ALTER' => 1, 'DROP' => 1, 'RENAME' => 1, 'GRANT' => 1, 'REVOKE' => 1, 'COMMENT' => 1, 'VACUUM' => 1, 'ANALYZE' => 1];

    private const TRANSACTION = ['BEGIN' => 1, 'START' => 1, 'COMMIT' => 1, 'ROLLBACK' => 1, 'SAVEPOINT' => 1, 'RELEASE' => 1, 'END' => 1];

    /** @var array<string, SqlStatement> */
    private static array $cache = [];

    public static function analyze(string $sql, string $driver = ''): SqlStatement
    {
        $key = $driver.'|'.$sql;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (count(self::$cache) > 2000) {
            self::$cache = [];
        }

        $tokens = Tokenizer::tokenize($sql, in_array($driver, ['mysql', 'mariadb'], true));
        $parsed = self::parse($tokens);

        return self::$cache[$key] = new SqlStatement(
            $parsed['op'],
            $parsed['writes'],
            $parsed['reads'],
            self::fingerprint($tokens),
            $parsed['upsert'],
        );
    }

    /** Drop the analysis cache (long-lived workers call this to bound memory; tests call it for isolation). */
    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $t
     * @return array{op: string, writes: list<TableRef>, reads: list<TableRef>, upsert: bool}
     */
    private static function parse(array $t): array
    {
        $n = count($t);
        $u = self::upper($t);
        $writes = [];
        $reads = [];
        $upsert = false;
        $cteNames = [];

        $i = 0;
        while ($i < $n && ($t[$i][1] === '(' || $t[$i][1] === ';') && $t[$i][0] === 'o') {
            $i++;
        }

        if (($u[$i] ?? '') === 'WITH') {
            $i++;
            if (($u[$i] ?? '') === 'RECURSIVE') {
                $i++;
            }
            while ($i < $n) {
                if (isset($t[$i]) && ($t[$i][0] === 'w' || $t[$i][0] === 'q')) {
                    $cteNames[strtolower($t[$i][1])] = true;
                    $i++;
                }
                if (self::isOp($t, $i, '(')) {
                    $i = self::matchParen($t, $i) + 1;
                }
                if (($u[$i] ?? '') === 'AS') {
                    $i++;
                }
                if (($u[$i] ?? '') === 'NOT') {
                    $i++;
                }
                if (($u[$i] ?? '') === 'MATERIALIZED') {
                    $i++;
                }
                if (self::isOp($t, $i, '(')) {
                    $close = self::matchParen($t, $i);
                    $sub = self::parse(array_slice($t, $i + 1, $close - $i - 1));
                    array_push($writes, ...$sub['writes']);
                    array_push($reads, ...$sub['reads']);
                    $upsert = $upsert || $sub['upsert'];
                    $i = $close + 1;
                }
                if (self::isOp($t, $i, ',')) {
                    $i++;

                    continue;
                }
                break;
            }
            while ($i < $n && $t[$i][0] === 'o' && $t[$i][1] === '(') {
                $i++;
            }
        }

        $kw = $u[$i] ?? '';
        $op = 'other';
        $skip = [];

        switch (true) {
            case $kw === 'INSERT' || $kw === 'REPLACE' || $kw === 'UPSERT':
                $op = 'insert';
                [$ref, $up] = self::parseInsert($t, $u, $i, $kw);
                $upsert = $upsert || $up || $kw !== 'INSERT';
                if ($ref !== null) {
                    $writes[] = $ref;
                }
                break;

            case $kw === 'UPDATE':
                $op = 'update';
                $ref = self::parseUpdate($t, $u, $i);
                if ($ref !== null) {
                    $writes[] = $ref;
                }
                break;

            case $kw === 'DELETE':
                $op = 'delete';
                [$refs, $skip] = self::parseDelete($t, $u, $i);
                array_push($writes, ...$refs);
                break;

            case $kw === 'TRUNCATE':
                $op = 'truncate';
                $j = $i + 1;
                if (($u[$j] ?? '') === 'TABLE') {
                    $j++;
                }
                while (($u[$j] ?? '') === 'ONLY') {
                    $j++;
                }
                while (($found = self::parseTableRef($t, $u, $j)) !== null) {
                    $found[0]->operation = 'delete';
                    $writes[] = $found[0];
                    $j = $found[1];
                    if (self::isOp($t, $j, ',')) {
                        $j++;

                        continue;
                    }
                    break;
                }
                break;

            case $kw === 'MERGE':
                $op = 'merge';
                $upsert = true;
                $j = $i + 1;
                if (($u[$j] ?? '') === 'TOP' && self::isOp($t, $j + 1, '(')) {
                    $j = self::matchParen($t, $j + 1) + 1;
                }
                if (($u[$j] ?? '') === 'INTO') {
                    $j++;
                }
                $found = self::parseTableRef($t, $u, $j);
                if ($found !== null) {
                    $found[0]->operation = 'update';
                    $writes[] = $found[0];
                }
                break;

            case $kw === 'SELECT' || $kw === 'VALUES' || $kw === 'TABLE' || $kw === 'FROM':
                $op = 'select';
                break;

            case isset(self::TRANSACTION[$kw]):
                $op = 'transaction';
                break;

            case isset(self::DDL[$kw]):
                $op = 'ddl';
                break;
        }

        array_push($reads, ...self::scanReads($t, $u, $skip));

        if ($op === 'merge') {
            for ($k = 0; $k < $n; $k++) {
                if (($u[$k] ?? '') === 'USING') {
                    $found = self::parseTableRef($t, $u, $k + 1);
                    if ($found !== null) {
                        $reads[] = $found[0];
                    }
                    break;
                }
            }
        }

        // `ON DUPLICATE KEY UPDATE` / `ON CONFLICT` anywhere in an INSERT
        if ($op === 'insert' && ! $upsert) {
            for ($k = 0; $k < $n - 1; $k++) {
                if (($u[$k] ?? '') === 'ON' && ((($u[$k + 1] ?? '') === 'DUPLICATE') || (($u[$k + 1] ?? '') === 'CONFLICT'))) {
                    $upsert = true;
                    break;
                }
            }
        }

        $writes = self::withoutCtes($writes, $cteNames);
        $reads = self::dedupe(self::withoutCtes($reads, $cteNames), $writes);

        return ['op' => $op, 'writes' => $writes, 'reads' => $reads, 'upsert' => $upsert];
    }

    // ------------------------------------------------------------------------------------------------
    // INSERT / UPDATE / DELETE
    // ------------------------------------------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     * @return array{0: TableRef|null, 1: bool}
     */
    private static function parseInsert(array $t, array $u, int $i, string $kw): array
    {
        $j = $i + 1;
        $upsert = false;
        while (isset($u[$j]) && in_array($u[$j], ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'], true)) {
            $j++;
        }
        if (($u[$j] ?? '') === 'OR') {
            $upsert = ($u[$j + 1] ?? '') === 'REPLACE';
            $j += 2;
        }
        if (($u[$j] ?? '') === 'INTO') {
            $j++;
        }

        $found = self::parseTableRef($t, $u, $j, true);
        if ($found === null) {
            return [null, $upsert];
        }
        [$ref, $j] = $found;

        $columns = [];
        if (self::isOp($t, $j, '(') && ($u[$j + 1] ?? '') !== 'SELECT' && ($u[$j + 1] ?? '') !== 'WITH') {
            $close = self::matchParen($t, $j);
            for ($k = $j + 1; $k < $close; $k++) {
                if (($t[$k][0] === 'w' || $t[$k][0] === 'q') && ! self::isOp($t, $k + 1, '.')) {
                    $columns[] = $t[$k][1];
                }
            }
            $j = $close + 1;
        }

        $rows = null;
        if (($u[$j] ?? '') === 'OVERRIDING') {
            $j += 3;                                  // OVERRIDING {SYSTEM|USER} VALUE
        }
        if (in_array($u[$j] ?? '', ['VALUES', 'VALUE'], true)) {
            $j++;
            $rows = 0;
            while (true) {
                if (($u[$j] ?? '') === 'ROW') {
                    $j++;
                }
                if (! self::isOp($t, $j, '(')) {
                    break;
                }
                $j = self::matchParen($t, $j) + 1;
                $rows++;
                if (self::isOp($t, $j, ',')) {
                    $j++;

                    continue;
                }
                break;
            }
        } elseif (($u[$j] ?? '') === 'DEFAULT' && ($u[$j + 1] ?? '') === 'VALUES') {
            $rows = 1;
        }

        $out = new TableRef($ref->name, $ref->schema, $ref->alias, 'insert', $rows, $columns);

        return [$out, $upsert || $kw === 'REPLACE'];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     */
    private static function parseUpdate(array $t, array $u, int $i): ?TableRef
    {
        $n = count($t);
        $j = $i + 1;
        while (isset($u[$j]) && in_array($u[$j], ['LOW_PRIORITY', 'IGNORE', 'ONLY'], true)) {
            $j++;
        }
        if (($u[$j] ?? '') === 'OR') {
            $j += 2;
        }
        if (($u[$j] ?? '') === 'TOP' && self::isOp($t, $j + 1, '(')) {
            $j = self::matchParen($t, $j + 1) + 1;
            if (($u[$j] ?? '') === 'PERCENT') {
                $j++;
            }
        }

        $found = self::parseTableRef($t, $u, $j);
        if ($found === null) {
            return null;
        }
        [$ref, $j] = $found;

        // find SET at depth 0, then the assigned columns
        $depth = 0;
        $set = null;
        for ($k = $j; $k < $n; $k++) {
            if ($t[$k][0] === 'o') {
                if ($t[$k][1] === '(') {
                    $depth++;
                } elseif ($t[$k][1] === ')') {
                    $depth--;
                }
            } elseif ($depth === 0 && ($u[$k] ?? '') === 'SET') {
                $set = $k;
                break;
            }
        }

        $columns = [];
        $fromIndex = null;
        if ($set !== null) {
            $depth = 0;
            $expectColumn = true;
            $lastIdent = null;
            for ($k = $set + 1; $k < $n; $k++) {
                $tok = $t[$k];
                if ($tok[0] === 'o' && $tok[1] === '(') {
                    $depth++;

                    continue;
                }
                if ($tok[0] === 'o' && $tok[1] === ')') {
                    $depth--;

                    continue;
                }
                if ($depth === 0 && isset($u[$k]) && in_array($u[$k], ['WHERE', 'FROM', 'RETURNING', 'OUTPUT', 'ORDER', 'LIMIT', 'OPTION'], true)) {
                    if ($u[$k] === 'FROM') {
                        $fromIndex = $k;
                    }
                    break;
                }
                if ($depth === 0 && $tok[0] === 'o' && $tok[1] === ',') {
                    $expectColumn = true;
                    $lastIdent = null;

                    continue;
                }
                if ($expectColumn && $depth === 0) {
                    if ($tok[0] === 'w' || $tok[0] === 'q') {
                        $lastIdent = $tok[1];

                        continue;
                    }
                    if ($tok[0] === 'o' && $tok[1] === '=' && $lastIdent !== null) {
                        $columns[] = $lastIdent;
                        $expectColumn = false;
                    }
                }
            }
        }

        // PostgreSQL / SQL Server: `UPDATE alias SET ... FROM table alias` – the target is the aliased table
        $target = new TableRef($ref->name, $ref->schema, $ref->alias, 'update', null, $columns);
        if ($fromIndex !== null && $ref->alias === null) {
            $f = $fromIndex + 1;
            while (($found = self::parseTableRef($t, $u, $f)) !== null) {
                if ($found[0]->alias !== null && strcasecmp($found[0]->alias, $ref->name) === 0) {
                    return new TableRef($found[0]->name, $found[0]->schema, $found[0]->alias, 'update', null, $columns);
                }
                $f = $found[1];
                if (self::isOp($t, $f, ',')) {
                    $f++;

                    continue;
                }
                // skip JOIN ... ON ... until the next table ref keyword
                $next = self::nextJoin($t, $u, $f);
                if ($next === null) {
                    break;
                }
                $f = $next;
            }
        }

        return $target;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     * @return array{0: list<TableRef>, 1: array<int, true>} written tables and token indexes that must not count as reads
     */
    private static function parseDelete(array $t, array $u, int $i): array
    {
        $n = count($t);
        $j = $i + 1;
        while (true) {
            if (($u[$j] ?? '') === 'TOP' && self::isOp($t, $j + 1, '(')) {
                $j = self::matchParen($t, $j + 1) + 1;
                if (($u[$j] ?? '') === 'PERCENT') {
                    $j++;
                }

                continue;
            }
            if (isset($u[$j]) && in_array($u[$j], ['LOW_PRIORITY', 'QUICK', 'IGNORE'], true)) {
                $j++;

                continue;
            }
            break;
        }

        if (($u[$j] ?? '') === 'FROM') {
            $found = self::parseTableRef($t, $u, $j + 1);
            if ($found === null) {
                return [[], []];
            }

            return [[new TableRef($found[0]->name, $found[0]->schema, $found[0]->alias, 'delete')], [$j => true]];
        }

        // DELETE a, b FROM a JOIN b ...   /   DELETE alias FROM table alias
        $fromAt = array_search('FROM', array_slice($u, $j, null, true), true);
        $names = [];
        for ($k = $j; $k < ($fromAt === false ? $n : $fromAt); $k++) {
            if ($t[$k][0] === 'w' || $t[$k][0] === 'q') {
                $names[] = $t[$k][1];
            }
        }
        $refs = [];
        if ($fromAt !== false) {
            $f = $fromAt + 1;
            while (($found = self::parseTableRef($t, $u, $f)) !== null) {
                $refs[] = $found[0];
                $f = $found[1];
                if (self::isOp($t, $f, ',')) {
                    $f++;

                    continue;
                }
                $next = self::nextJoin($t, $u, $f);
                if ($next === null) {
                    break;
                }
                $f = $next;
            }
        }

        $targets = [];
        foreach ($names as $name) {
            foreach ($refs as $ref) {
                if (($ref->alias !== null && strcasecmp($ref->alias, $name) === 0) || strcasecmp($ref->name, $name) === 0) {
                    $targets[] = new TableRef($ref->name, $ref->schema, $ref->alias, 'delete');
                    break;
                }
            }
        }

        return [$targets, []];
    }

    // ------------------------------------------------------------------------------------------------
    // Table references
    // ------------------------------------------------------------------------------------------------

    /**
     * Parse `[ONLY] [db.][schema.]table [[AS] alias]` at $j.
     *
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     * @param  bool  $allowParen  true where a column list may follow the table (INSERT INTO t (a, b))
     * @return array{0: TableRef, 1: int}|null the reference and the index after it
     */
    private static function parseTableRef(array $t, array $u, int $j, bool $allowParen = false): ?array
    {
        while (isset($u[$j]) && ($u[$j] === 'ONLY' || $u[$j] === 'LATERAL')) {
            $j++;
        }
        if (! isset($t[$j]) || ($t[$j][0] !== 'w' && $t[$j][0] !== 'q')) {
            return null;
        }
        if ($t[$j][0] === 'w' && isset(self::NOT_ALIAS[$u[$j]]) && $u[$j] !== 'LOCK') {
            return null;
        }

        $parts = [];
        while (true) {
            $parts[] = $t[$j][1];
            $j++;
            if (self::isOp($t, $j, '.')) {
                $j++;
                if (self::isOp($t, $j, '.')) {      // SQL Server db..table
                    $j++;
                }
                if (isset($t[$j]) && ($t[$j][0] === 'w' || $t[$j][0] === 'q')) {
                    continue;
                }
            }
            break;
        }
        if (! $allowParen && self::isOp($t, $j, '(')) {
            return null;                              // function call: generate_series(...), unnest(...)
        }

        $alias = null;
        if (($u[$j] ?? '') === 'AS') {
            $j++;
            if (isset($t[$j]) && ($t[$j][0] === 'w' || $t[$j][0] === 'q')) {
                $alias = $t[$j][1];
                $j++;
            }
        } elseif (isset($t[$j]) && ($t[$j][0] === 'q' || ($t[$j][0] === 'w' && ! isset(self::NOT_ALIAS[$u[$j]])))) {
            $alias = $t[$j][1];
            $j++;
        }
        // SQL Server table hints: WITH (NOLOCK)
        if (($u[$j] ?? '') === 'WITH' && self::isOp($t, $j + 1, '(')) {
            $j = self::matchParen($t, $j + 1) + 1;
        }

        $name = (string) array_pop($parts);
        $schema = implode('.', array_filter($parts, static fn (string $p): bool => $p !== ''));

        return [new TableRef($name, $schema === '' ? null : $schema, $alias), $j];
    }

    /**
     * Tables read by FROM / JOIN clauses anywhere in the statement.
     *
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     * @param  array<int, true>  $skip  indexes of FROM tokens that belong to a write target (DELETE FROM)
     * @return list<TableRef>
     */
    private static function scanReads(array $t, array $u, array $skip): array
    {
        $n = count($t);
        $reads = [];
        $stack = [];

        for ($i = 0; $i < $n; $i++) {
            $tok = $t[$i];
            if ($tok[0] === 'o') {
                if ($tok[1] === '(') {
                    $prev = $i > 0 ? ($u[$i - 1] ?? '') : '';
                    $stack[] = isset(self::FROM_FUNCTIONS[$prev]);
                } elseif ($tok[1] === ')') {
                    array_pop($stack);
                }

                continue;
            }
            $kw = $u[$i] ?? '';
            if ($kw !== 'FROM' && $kw !== 'JOIN') {
                continue;
            }
            if (isset($skip[$i]) || ($stack !== [] && end($stack) === true)) {
                continue;
            }

            $j = $i + 1;
            while (($found = self::parseTableRef($t, $u, $j)) !== null) {
                $reads[] = $found[0];
                $j = $found[1];
                if ($kw === 'FROM' && self::isOp($t, $j, ',')) {
                    $j++;

                    continue;
                }
                break;
            }
        }

        return $reads;
    }

    /**
     * Index of the first token after the next JOIN keyword at or after $f (ignoring ON conditions), or null.
     *
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     */
    private static function nextJoin(array $t, array $u, int $f): ?int
    {
        $n = count($t);
        $depth = 0;
        for ($k = $f; $k < $n; $k++) {
            if ($t[$k][0] === 'o') {
                $depth += $t[$k][1] === '(' ? 1 : ($t[$k][1] === ')' ? -1 : 0);

                continue;
            }
            if ($depth === 0 && isset($u[$k]) && in_array($u[$k], ['WHERE', 'SET', 'ORDER', 'LIMIT', 'GROUP', 'RETURNING', 'OUTPUT', 'OPTION'], true)) {
                return null;
            }
            if ($depth === 0 && ($u[$k] ?? '') === 'JOIN') {
                return $k + 1;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------------------------------------

    /**
     * @param  list<array{0: string, 1: string}>  $t
     * @return list<string>
     */
    private static function upper(array $t): array
    {
        $u = [];
        foreach ($t as $token) {
            $u[] = $token[0] === 'w' ? strtoupper($token[1]) : '';
        }

        return $u;
    }

    /** @param  list<array{0: string, 1: string}>  $t */
    private static function isOp(array $t, int $i, string $char): bool
    {
        return isset($t[$i]) && $t[$i][0] === 'o' && $t[$i][1] === $char;
    }

    /** @param  list<array{0: string, 1: string}>  $t */
    private static function matchParen(array $t, int $open): int
    {
        $depth = 0;
        $n = count($t);
        for ($i = $open; $i < $n; $i++) {
            if ($t[$i][0] !== 'o') {
                continue;
            }
            if ($t[$i][1] === '(') {
                $depth++;
            } elseif ($t[$i][1] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $n - 1;
    }

    /**
     * @param  list<TableRef>  $refs
     * @param  array<string, true>  $cteNames
     * @return list<TableRef>
     */
    private static function withoutCtes(array $refs, array $cteNames): array
    {
        if ($cteNames === []) {
            return $refs;
        }

        return array_values(array_filter($refs, static fn (TableRef $r): bool => $r->schema !== null || ! isset($cteNames[strtolower($r->name)])));
    }

    /**
     * Unique reads, minus the tables the statement writes itself.
     *
     * @param  list<TableRef>  $reads
     * @param  list<TableRef>  $writes
     * @return list<TableRef>
     */
    private static function dedupe(array $reads, array $writes): array
    {
        $own = [];
        foreach ($writes as $w) {
            $own[strtolower($w->qualified())] = true;
        }
        $out = [];
        foreach ($reads as $r) {
            $key = strtolower($r->qualified());
            if (isset($own[$key])) {
                continue;
            }
            $out[$key] ??= $r;
        }

        return array_values($out);
    }

    /** @param  list<array{0: string, 1: string}>  $tokens */
    private static function fingerprint(array $tokens): string
    {
        $parts = [];
        foreach ($tokens as [$type, $value]) {
            $parts[] = match ($type) {
                's', 'n', 'p' => '?',
                'q' => '"'.strtolower($value).'"',
                'w' => strtolower($value),
                default => $value,
            };
        }
        $s = implode(' ', $parts);
        $s = (string) preg_replace('/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '(?)', $s);
        $s = (string) preg_replace('/(\(\?\))(?:\s*,\s*\(\?\))+/', '$1', $s);

        return trim($s, ' ;');
    }
}
