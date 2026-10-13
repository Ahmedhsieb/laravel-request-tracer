<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

/**
 * Matches class names against namespace patterns: `App\Services` (the namespace and everything below it, or the class itself),
 * `App\*\Services` (`*` stands for one namespace segment), `App\Domain\*` (anything below). Case-insensitive.
 */
final class ClassPatterns
{
    /** @var array<string, string> */
    private static array $compiled = [];

    /** @param  array<int, mixed>  $patterns */
    public static function matches(string $class, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            $regex = self::$compiled[$pattern] ??= '~^'.str_replace('\\*', '[^\\\\]*', preg_quote(ltrim($pattern, '\\'), '~')).'(?:\\\\.*)?$~i';
            if (preg_match($regex, $class) === 1) {
                return true;
            }
        }

        return false;
    }
}
