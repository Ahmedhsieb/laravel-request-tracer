<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

/**
 * The safety net of `unlimited` mode. With no cap on what is stored, the only thing standing between a huge value and a fatal
 * "Allowed memory size exhausted" (which would break the request: hard rule 2) is how much memory the process still has, so the
 * tracer stops capturing once it has used {@see self::SHARE} of PHP's `memory_limit`. Nothing is kept here: it reads the numbers every time.
 */
final class MemoryGuard
{
    /** What the process may have used before the tracer stops storing more. */
    public const SHARE = 0.7;

    /** A rough size, in bytes, of one cloned item (a key and its value): what the budget of items is worked out with. */
    private const BYTES_PER_ITEM = 700;

    /** `memory_limit` in bytes, 0 when there is none (-1). */
    public static function limit(?string $raw = null): int
    {
        $raw = trim($raw ?? (string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $number = (float) $raw;
        $unit = strtolower(substr($raw, -1));
        $bytes = match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };

        return $bytes > 0 ? (int) $bytes : 0;
    }

    /** True when the process has used {@see self::SHARE} of its memory limit (never when there is no limit). */
    public static function low(?int $usage = null, ?string $limit = null): bool
    {
        $max = self::limit($limit);

        return $max > 0 && ($usage ?? memory_get_usage(true)) >= $max * self::SHARE;
    }

    /**
     * How many items a value may be cloned with, so that cloning it cannot use more than what is left under the share:
     * -1 (no cap) when there is no memory limit.
     */
    public static function itemBudget(?int $usage = null, ?string $limit = null): int
    {
        $max = self::limit($limit);
        if ($max <= 0) {
            return -1;
        }
        $left = $max * self::SHARE - ($usage ?? memory_get_usage(true));

        return max(1000, (int) ($left / self::BYTES_PER_ITEM));
    }
}
