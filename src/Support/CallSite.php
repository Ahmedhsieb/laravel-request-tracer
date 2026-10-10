<?php

declare(strict_types=1);

namespace RequestTracer\Support;

/** Finds the application file:line that caused something (a query, a log line, a trace() call). */
final class CallSite
{
    /** @return array{0: string|null, 1: int|null} relative file, line */
    public static function find(int $limit = 40): array
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $limit) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file !== null && ! Paths::isInternal($file)) {
                return [Paths::relative($file), $frame['line'] ?? null];
            }
        }

        return [null, null];
    }
}
