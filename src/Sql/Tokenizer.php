<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Sql;

/**
 * A small SQL tokenizer, good enough to find statement types, table names and placeholders in MySQL, PostgreSQL,
 * SQLite and SQL Server dialects. It is not a parser: it only needs to be right about quoting, comments and nesting.
 *
 * Token = [type, value]:
 *   w  bare word (identifier or keyword)        q  quoted identifier ("x", `x`, [x]) – value is unquoted
 *   s  string literal (value is the raw text)   n  number
 *   p  placeholder (?, $1, :name, @name)        o  any other single character (punctuation, operators)
 */
final class Tokenizer
{
    /** Words after which `[` opens a SQL Server bracket identifier (otherwise it is an array subscript). */
    private const BRACKET_AFTER = [
        'SELECT' => 1, 'FROM' => 1, 'JOIN' => 1, 'INTO' => 1, 'UPDATE' => 1, 'TABLE' => 1, 'ON' => 1, 'AS' => 1, 'AND' => 1,
        'OR' => 1, 'SET' => 1, 'BY' => 1, 'WHERE' => 1, 'HAVING' => 1, 'DELETE' => 1, 'INSERT' => 1, 'NOT' => 1, 'IN' => 1,
        'TOP' => 1, 'DISTINCT' => 1, 'USING' => 1, 'MERGE' => 1, 'TRUNCATE' => 1, 'WHEN' => 1, 'THEN' => 1, 'ELSE' => 1, 'CASE' => 1,
    ];

    /** @return list<array{0: string, 1: string}> */
    public static function tokenize(string $sql, bool $backslashEscapes = false): array
    {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $c = $sql[$i];

            if ($c === ' ' || $c === "\n" || $c === "\t" || $c === "\r" || $c === "\f") {
                $i++;

                continue;
            }

            // comments
            if ($c === '-' && ($sql[$i + 1] ?? '') === '-') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;

                continue;
            }
            if ($c === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            if ($c === "'") {
                $j = $i + 1;
                while ($j < $length) {
                    if ($sql[$j] === "'") {
                        if (($sql[$j + 1] ?? '') === "'") {
                            $j += 2;

                            continue;
                        }
                        break;
                    }
                    if ($backslashEscapes && $sql[$j] === '\\') {
                        $j += 2;

                        continue;
                    }
                    $j++;
                }
                $tokens[] = ['s', substr($sql, $i + 1, $j - $i - 1)];
                $i = $j + 1;

                continue;
            }

            if ($c === '"' || $c === '`') {
                [$value, $i] = self::quoted($sql, $i, $c, $length);
                $tokens[] = ['q', $value];

                continue;
            }

            if ($c === '[' && self::bracketOpensIdentifier($tokens)) {
                $end = strpos($sql, ']', $i + 1);
                $end = $end === false ? $length : $end;
                $tokens[] = ['q', substr($sql, $i + 1, $end - $i - 1)];
                $i = $end + 1;

                continue;
            }

            if ($c === '$') {
                // $1 positional placeholder, or a $tag$ ... $tag$ dollar-quoted string
                if (preg_match('/\G\$(\d+)/A', $sql, $m, 0, $i) === 1) {
                    $tokens[] = ['p', $m[0]];
                    $i += strlen($m[0]);

                    continue;
                }
                if (preg_match('/\G\$([A-Za-z_]*)\$/A', $sql, $m, 0, $i) === 1) {
                    $tag = $m[0];
                    $end = strpos($sql, $tag, $i + strlen($tag));
                    $end = $end === false ? $length : $end;
                    $tokens[] = ['s', substr($sql, $i + strlen($tag), max(0, $end - $i - strlen($tag)))];
                    $i = min($length, $end + strlen($tag));

                    continue;
                }
            }

            if ($c === '?') {
                $tokens[] = ['p', '?'];
                $i++;

                continue;
            }

            if (($c === ':' || $c === '@') && preg_match('/\G[:@][A-Za-z_][A-Za-z0-9_]*/A', $sql, $m, 0, $i) === 1) {
                $prev = $tokens[count($tokens) - 1] ?? null;
                if (! ($c === ':' && $prev !== null && $prev[0] === 'o' && $prev[1] === ':')) {
                    $tokens[] = ['p', $m[0]];
                    $i += strlen($m[0]);

                    continue;
                }
            }

            if (ctype_digit($c) || ($c === '.' && ctype_digit($sql[$i + 1] ?? ''))) {
                if (preg_match('/\G(?:0[xX][0-9A-Fa-f]+|\d*\.?\d+(?:[eE][+-]?\d+)?)/A', $sql, $m, 0, $i) === 1) {
                    $tokens[] = ['n', $m[0]];
                    $i += strlen($m[0]);

                    continue;
                }
            }

            if (preg_match('/\G[\p{L}_][\p{L}\p{N}_$#]*/Au', $sql, $m, 0, $i) === 1) {
                $tokens[] = ['w', $m[0]];
                $i += strlen($m[0]);

                continue;
            }

            $tokens[] = ['o', $c];
            $i++;
        }

        return $tokens;
    }

    /** @return array{0: string, 1: int} unquoted value and the index after the closing quote */
    private static function quoted(string $sql, int $start, string $quote, int $length): array
    {
        $value = '';
        $j = $start + 1;
        while ($j < $length) {
            if ($sql[$j] === $quote) {
                if (($sql[$j + 1] ?? '') === $quote) {
                    $value .= $quote;
                    $j += 2;

                    continue;
                }
                break;
            }
            $value .= $sql[$j];
            $j++;
        }

        return [$value, $j + 1];
    }

    /** @param  list<array{0: string, 1: string}>  $tokens */
    private static function bracketOpensIdentifier(array $tokens): bool
    {
        $prev = $tokens[count($tokens) - 1] ?? null;
        if ($prev === null) {
            return true;
        }
        if ($prev[0] === 'o') {
            return $prev[1] !== ')' && $prev[1] !== ']';
        }
        if ($prev[0] === 'w') {
            return isset(self::BRACKET_AFTER[strtoupper($prev[1])]);
        }

        return false;
    }
}
