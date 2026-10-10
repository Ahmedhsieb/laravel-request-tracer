<?php

declare(strict_types=1);

namespace RequestTracer\Sql;

/**
 * Names the column each `?` placeholder is compared with or assigned to, so bindings can be masked by column name
 * (`where password = ?`, `insert into users (email, api_token) values (?, ?)`, `update users set secret = ?`).
 * Unknown positions are null.
 */
final class BindingNamer
{
    /** @var array<string, list<string|null>> */
    private static array $cache = [];

    private const WHERE_STOP = ['WHERE' => 1, 'FROM' => 1, 'RETURNING' => 1, 'OUTPUT' => 1, 'ORDER' => 1, 'LIMIT' => 1, 'OPTION' => 1, 'ON' => 1, 'WHEN' => 1];

    /** @return list<string|null> one entry per placeholder, in order */
    public static function names(string $sql, string $driver = ''): array
    {
        $key = $driver.'|'.$sql;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        if (count(self::$cache) > 2000) {
            self::$cache = [];
        }

        $t = Tokenizer::tokenize($sql, in_array($driver, ['mysql', 'mariadb'], true));
        $u = [];
        foreach ($t as $token) {
            $u[] = $token[0] === 'w' ? strtoupper($token[1]) : '';
        }

        $placeholders = [];
        foreach ($t as $i => $token) {
            if ($token[0] === 'p') {
                $placeholders[] = $i;
            }
        }

        $assigned = self::specialNames($t, $u, $sql, $driver);
        $names = [];
        foreach ($placeholders as $i) {
            $names[] = $assigned[$i] ?? self::backwardName($t, $u, $i);
        }

        return self::$cache[$key] = $names;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * Names for placeholders inside INSERT … VALUES tuples and UPDATE … SET assignments.
     *
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     * @return array<int, string>
     */
    private static function specialNames(array $t, array $u, string $sql, string $driver): array
    {
        $n = count($t);
        $out = [];
        $first = 0;
        while ($first < $n && ($u[$first] === '' || $u[$first] === 'WITH')) {
            if ($u[$first] === 'WITH') {
                break;
            }
            $first++;
        }
        $kw = $u[$first] ?? '';

        if ($kw === 'INSERT' || $kw === 'REPLACE') {
            $statement = SqlAnalyzer::analyze($sql, $driver);
            $columns = $statement->writes[0]->columns ?? [];
            $valuesAt = null;
            for ($i = $first; $i < $n; $i++) {
                if ($u[$i] === 'VALUES' || $u[$i] === 'VALUE') {
                    $valuesAt = $i;
                    break;
                }
            }
            if ($valuesAt !== null && $columns !== []) {
                $i = $valuesAt + 1;
                while ($i < $n) {
                    if (($u[$i] ?? '') === 'ROW') {
                        $i++;
                    }
                    if (! ($t[$i][0] === 'o' && $t[$i][1] === '(')) {
                        break;
                    }
                    $depth = 0;
                    $element = 0;
                    for (; $i < $n; $i++) {
                        $tok = $t[$i];
                        if ($tok[0] === 'o' && $tok[1] === '(') {
                            $depth++;
                        } elseif ($tok[0] === 'o' && $tok[1] === ')') {
                            $depth--;
                            if ($depth === 0) {
                                $i++;
                                break;
                            }
                        } elseif ($depth === 1 && $tok[0] === 'o' && $tok[1] === ',') {
                            $element++;
                        } elseif ($tok[0] === 'p' && isset($columns[$element])) {
                            $out[$i] = $columns[$element];
                        }
                    }
                    if (isset($t[$i]) && $t[$i][0] === 'o' && $t[$i][1] === ',') {
                        $i++;

                        continue;
                    }
                    break;
                }
            }
        }

        if ($kw === 'UPDATE') {
            $set = null;
            $depth = 0;
            for ($i = $first; $i < $n; $i++) {
                if ($t[$i][0] === 'o') {
                    $depth += $t[$i][1] === '(' ? 1 : ($t[$i][1] === ')' ? -1 : 0);
                } elseif ($depth === 0 && $u[$i] === 'SET') {
                    $set = $i;
                    break;
                }
            }
            if ($set !== null) {
                $depth = 0;
                $column = null;
                $last = null;
                $expect = true;
                for ($i = $set + 1; $i < $n; $i++) {
                    $tok = $t[$i];
                    if ($tok[0] === 'o' && $tok[1] === '(') {
                        $depth++;

                        continue;
                    }
                    if ($tok[0] === 'o' && $tok[1] === ')') {
                        $depth--;

                        continue;
                    }
                    if ($depth === 0 && isset(self::WHERE_STOP[$u[$i]])) {
                        break;
                    }
                    if ($depth === 0 && $tok[0] === 'o' && $tok[1] === ',') {
                        $expect = true;
                        $last = null;
                        $column = null;

                        continue;
                    }
                    if ($expect && $depth === 0) {
                        if ($tok[0] === 'w' || $tok[0] === 'q') {
                            $last = $tok[1];
                        } elseif ($tok[0] === 'o' && $tok[1] === '=' && $last !== null) {
                            $column = $last;
                            $expect = false;
                        }

                        continue;
                    }
                    if ($tok[0] === 'p' && $column !== null) {
                        $out[$i] = $column;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * `col = ?`, `col <> ?`, `col like ?`, `col in (?, ?)`, `col between ? and ?`, `lower(col) = ?`
     *
     * @param  list<array{0: string, 1: string}>  $t
     * @param  list<string>  $u
     */
    private static function backwardName(array $t, array $u, int $k): ?string
    {
        $j = $k - 1;
        $limit = max(0, $k - 40);

        // step over list members and parentheses: in (?, ?, ?)
        while ($j >= $limit) {
            $tok = $t[$j];
            if ($tok[0] === 'p' || $tok[0] === 'n' || $tok[0] === 's' || ($tok[0] === 'o' && ($tok[1] === ',' || $tok[1] === '('))) {
                $j--;

                continue;
            }
            break;
        }

        // operators / keywords between the column and the value
        $sawOperator = false;
        while ($j >= $limit) {
            $tok = $t[$j];
            if (($sawOperator && ($tok[0] === 'p' || $tok[0] === 'n' || $tok[0] === 's')) || ($tok[0] === 'o' && in_array($tok[1], ['=', '<', '>', '!', '~'], true)) || ($tok[0] === 'w' && in_array($u[$j], ['LIKE', 'ILIKE', 'IN', 'NOT', 'BETWEEN', 'IS', 'AND', 'REGEXP', 'RLIKE', 'SIMILAR', 'TO', 'ANY', 'ALL'], true))) {
                $sawOperator = true;
                $j--;

                continue;
            }
            break;
        }
        if (! $sawOperator || $j < 0) {
            return null;
        }

        $tok = $t[$j];
        if ($tok[0] === 'o' && $tok[1] === ')') {
            // lower(col) = ?  -> use the identifier inside the parentheses
            $depth = 0;
            for ($m = $j; $m >= $limit; $m--) {
                if ($t[$m][0] === 'o' && $t[$m][1] === ')') {
                    $depth++;
                } elseif ($t[$m][0] === 'o' && $t[$m][1] === '(') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($depth === 1 && ($t[$m][0] === 'w' || $t[$m][0] === 'q') && ! isset(self::WHERE_STOP[$u[$m]])) {
                    return $t[$m][1];
                }
            }

            return null;
        }
        if ($tok[0] === 'w' || $tok[0] === 'q') {
            return $tok[1];
        }

        return null;
    }
}
