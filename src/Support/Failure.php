<?php

declare(strict_types=1);

namespace RequestTracer\Support;

/**
 * Internal failures of the tracer end up here: logged to the `request-tracer` channel, never thrown.
 * The tracer must never break a request, so this class itself never throws either.
 */
final class Failure
{
    /** @var (\Closure(string, array<string, mixed>): void)|null */
    private static ?\Closure $sink = null;

    private static bool $reporting = false;

    /** Called once at boot by the service provider. */
    public static function useSink(?\Closure $sink): void
    {
        self::$sink = $sink;
    }

    /** True while an internal failure is being logged (collectors ignore the log lines that produces). */
    public static function reporting(): bool
    {
        return self::$reporting;
    }

    public static function report(\Throwable $e, string $where): void
    {
        if (self::$reporting) {
            return;
        }
        self::$reporting = true;
        try {
            $context = [
                'where' => $where,
                'exception' => $e::class,
                'file' => Paths::relative($e->getFile()),
                'line' => $e->getLine(),
            ];
            if (self::$sink !== null) {
                (self::$sink)('['.Brand::NAME.'] '.$where.': '.$e->getMessage(), $context);
            } else {
                error_log('['.Brand::NAME.'] '.$where.': '.$e->getMessage().' ('.$e::class.' at '.$context['file'].':'.$e->getLine().')');
            }
        } catch (\Throwable) {
            // nothing left to do
        } finally {
            self::$reporting = false;
        }
    }

    /** Debug-level note (invalid tokens and similar expected situations). */
    public static function debug(string $message): void
    {
        $was = self::$reporting;
        self::$reporting = true;
        try {
            if (self::$sink !== null) {
                (self::$sink)('[debug] ['.Brand::NAME.'] '.$message, ['level' => 'debug']);
            }
        } catch (\Throwable) {
        } finally {
            self::$reporting = $was;
        }
    }

    /**
     * Run $callback; on any Throwable log it and return $fallback.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    public static function guard(callable $callback, string $where, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::report($e, $where);

            return $fallback;
        }
    }
}
