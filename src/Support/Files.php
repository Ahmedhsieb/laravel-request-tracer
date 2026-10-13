<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

/** Small filesystem helpers for the generated-code caches. */
final class Files
{
    /** Write atomically (temp file + rename) and refresh OPcache, so concurrent requests never see a half-written file. */
    public static function write(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}");
        }
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (file_put_contents($tmp, $contents) === false) {
            throw new \RuntimeException("Cannot write {$tmp}");
        }
        if (! @rename($tmp, $path)) {                          // Windows cannot rename over an open file
            @unlink($path);
            if (! @rename($tmp, $path)) {
                @unlink($tmp);
                throw new \RuntimeException("Cannot replace {$path}");
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}
