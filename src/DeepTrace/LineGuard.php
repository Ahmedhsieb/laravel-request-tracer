<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\DeepTrace;

/**
 * Proves that a compiled file keeps every original token on its original line (so stack traces and the dashboard line up
 * with the user's editor), and that it still parses.
 */
final class LineGuard
{
    /**
     * @param  array<int, int>  $replaced  ranges of the original that were replaced on purpose (start => end exclusive)
     *
     * @throws CompileException
     */
    public static function verify(string $original, string $compiled, array $replaced = []): void
    {
        if (substr_count($original, "\n") !== substr_count($compiled, "\n")) {
            throw new CompileException('the compiled file has a different number of lines');
        }

        try {
            $compiledTokens = token_get_all($compiled, TOKEN_PARSE);
        } catch (\ParseError $e) {
            throw new CompileException('the compiled file does not parse: '.$e->getMessage().' on line '.$e->getLine());
        }

        $originalTokens = self::significant(token_get_all($original));
        foreach ($replaced as $start => $end) {
            // the swapped operator token ("<", "===") is expected to disappear from its line
            $text = substr($original, $start, $end - $start);
            $line = substr_count($original, "\n", 0, $start) + 1;
            foreach ($originalTokens as $i => [$t, $l]) {
                if ($t === $text && $l === $line) {
                    unset($originalTokens[$i]);

                    break;
                }
            }
        }

        $mismatch = self::firstLineMismatch(array_values($originalTokens), self::significant($compiledTokens));
        if ($mismatch !== null) {
            throw new CompileException($mismatch);
        }
    }

    /**
     * Every original token must reappear, in order, on the same line.
     *
     * @param  list<array{0: string, 1: int}>  $original  [text, line]
     * @param  list<array{0: string, 1: int}>  $compiled
     */
    public static function firstLineMismatch(array $original, array $compiled): ?string
    {
        $j = 0;
        $count = count($compiled);
        foreach ($original as [$text, $line]) {
            $found = false;
            while ($j < $count) {
                if ($compiled[$j][0] === $text && $compiled[$j][1] === $line) {
                    $found = true;
                    $j++;

                    break;
                }
                $j++;
            }
            if (! $found) {
                return "token \"{$text}\" of line {$line} did not survive on its line in the compiled file";
            }
        }

        return null;
    }

    /**
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     * @return list<array{0: string, 1: int}>
     */
    public static function significant(array $tokens): array
    {
        $out = [];
        $line = 1;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                [$id, $text, $line] = $token;
                if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT || $id === T_OPEN_TAG || $id === T_CLOSE_TAG) {
                    $line += substr_count($text, "\n");

                    continue;
                }
                // heredocs/strings span lines: compare their first line only
                $out[] = [$text, $line];
                $line += substr_count($text, "\n");
            } else {
                $out[] = [$token, $line];
            }
        }

        return $out;
    }
}
