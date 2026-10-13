<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

/** file/line lookups through reflection (never throws). */
final class Locator
{
    /** @return array{0: string|null, 1: int|null} relative file and start line of Class::method */
    public static function method(string|object $class, string $method): array
    {
        try {
            $r = new \ReflectionMethod($class, $method);

            return [Paths::relative($r->getFileName() ?: null), $r->getStartLine() ?: null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /** @return array{0: string|null, 1: int|null} */
    public static function callable(callable|\Closure $callable): array
    {
        try {
            $r = match (true) {
                $callable instanceof \Closure => new \ReflectionFunction($callable),
                is_array($callable) => new \ReflectionMethod($callable[0], $callable[1]),
                is_string($callable) && str_contains($callable, '::') => new \ReflectionMethod($callable),
                is_string($callable) => new \ReflectionFunction($callable),
                is_object($callable) => new \ReflectionMethod($callable, '__invoke'),
                default => throw new \InvalidArgumentException('not a locatable callable'),
            };

            return [Paths::relative($r->getFileName() ?: null), $r->getStartLine() ?: null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /** @return array{0: string|null, 1: int|null} */
    public static function classFile(string $class): array
    {
        try {
            if (! class_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
                return [null, null];
            }
            $r = new \ReflectionClass($class);

            return [Paths::relative($r->getFileName() ?: null), $r->getStartLine() ?: null];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    /** "App\Http\Controllers\OrderController" -> "OrderController" */
    public static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** Human label for any callable/listener description ("Class@method", Closure (file:line)). */
    public static function describeCallable(mixed $callable): string
    {
        if (is_string($callable)) {
            return $callable;
        }
        if (is_array($callable) && isset($callable[0], $callable[1])) {
            return (is_object($callable[0]) ? $callable[0]::class : (string) $callable[0]).'@'.(string) $callable[1];
        }
        if ($callable instanceof \Closure) {
            [$file, $line] = self::callable($callable);

            return 'Closure'.($file !== null ? " ({$file}:{$line})" : '');
        }
        if (is_object($callable)) {
            return $callable::class;
        }

        return 'callable';
    }
}
