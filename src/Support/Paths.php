<?php

declare(strict_types=1);

namespace RequestTracer\Support;

use RequestTracer\DeepTrace\Loader;

/**
 * File path helpers: stored paths are relative to the application base path (forward slashes) when possible.
 * Files compiled for Deep trace are reported under the path of the original they were compiled from.
 */
final class Paths
{
    private static ?string $base = null;

    private static ?string $vendor = null;

    private static ?string $packageSrc = null;

    /** @var list<string> directories of generated code (proxy classes): never "application code" */
    private static array $generated = [];

    /** Call from the service provider (and tests) when the application is (re)created. */
    public static function configure(string $basePath): void
    {
        self::$base = self::normalize($basePath);
        self::$vendor = self::$base.'/vendor/';
        self::$packageSrc = self::normalize(dirname(__DIR__)).'/';
    }

    /** Generated code (traced subclasses) must not be reported as the place a query or log line came from. */
    public static function markGenerated(string $directory): void
    {
        $directory = self::normalize($directory).'/';
        if (! in_array($directory, self::$generated, true)) {
            self::$generated[] = $directory;
        }
    }

    /** The project's base path (forward slashes, no trailing slash), or '' before the application booted. */
    public static function base(): string
    {
        return self::$base ?? '';
    }

    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    public static function relative(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }
        $file = str_replace('\\', '/', $file);
        $file = Loader::originalOf($file) ?? $file;
        if (self::$base !== null && str_starts_with($file, self::$base.'/')) {
            return substr($file, strlen(self::$base) + 1);
        }

        return $file;
    }

    public static function absolute(string $relative): string
    {
        if ($relative === '' || preg_match('~^(?:[A-Za-z]:)?/~', $relative) === 1) {
            return $relative;
        }

        return (self::$base ?? '').'/'.$relative;
    }

    /** True for files that belong to Composer packages or to this package (never "application code"). */
    public static function isInternal(?string $file): bool
    {
        if ($file === null) {
            return true;
        }
        $file = str_replace('\\', '/', $file);
        $file = Loader::originalOf($file) ?? $file;

        foreach (self::$generated as $directory) {
            if (str_starts_with($file, $directory)) {
                return true;
            }
        }

        return (self::$vendor !== null && str_starts_with($file, self::$vendor))
            || (self::$packageSrc !== null && str_starts_with($file, self::$packageSrc))
            || str_contains($file, '/vendor/');
    }
}
