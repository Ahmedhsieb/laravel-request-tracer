<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Recording;

use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Illuminate\Http\Request;

/**
 * "Is somebody listening?": the switch behind `php artisan request-tracer:listen`.
 *
 * The listen command touches a small file every second. Every other process (a PHP-FPM worker, `artisan serve`, a queue worker,
 * a command) only has to look at when that file was last touched: fresh means "record what you do now", stale means "do nothing".
 * Stop the command and recording stops within a few seconds, with nothing to clean up. The check costs one `stat()`.
 */
final class Listening
{
    /** The file counts as fresh for this long after its last touch (the command touches it every second). */
    public const FRESH_FOR = 4;

    private static ?string $file = null;

    private static float $checkedAt = 0.0;

    private static bool $lastResult = false;

    /** Point the heartbeat at another file (tests). */
    public static function useFile(?string $file): void
    {
        self::$file = $file;
        self::$checkedAt = 0.0;
    }

    public static function file(): string
    {
        return self::$file ??= App::application()->storagePath('framework/request-tracer/listening');
    }

    /** True while a listen command is running (its heartbeat is fresh). */
    public static function active(): bool
    {
        $file = self::file();
        clearstatcache(true, $file);
        $touched = @filemtime($file);

        return $touched !== false && (time() - $touched) <= self::FRESH_FOR;
    }

    /** Called by the listen command every second. */
    public static function beat(): void
    {
        $file = self::file();
        if (! is_dir(dirname($file))) {
            @mkdir(dirname($file), 0775, true);
        }
        @file_put_contents($file, (string) getmypid());
        self::$checkedAt = 0.0;
    }

    /** Called by the listen command when it stops. */
    public static function stop(): void
    {
        @unlink(self::file());
        self::$checkedAt = 0.0;
    }

    /**
     * "Should code be compiled for line-by-line tracing right now?" The autoloader asks this for every class, so the answer is
     * remembered for a second. True when the tracer records everything, somebody is listening, the process was started with
     * `REQUEST_TRACER=1`, or the current request carries a valid signed header.
     */
    public static function wanted(): bool
    {
        $now = microtime(true);
        if ($now - self::$checkedAt < 1.0) {
            return self::$lastResult;
        }
        self::$checkedAt = $now;

        return self::$lastResult = Failure::guard(static fn (): bool => self::compute(), 'deciding whether to trace code', false);
    }

    /** `REQUEST_TRACER=1 php artisan ...` */
    public static function flagged(): bool
    {
        $value = getenv('REQUEST_TRACER');

        return is_string($value) && in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function compute(): bool
    {
        $config = App::config();
        if (! $config->get('request-tracer.enabled', true)) {
            return false;
        }
        $mode = $config->get('request-tracer.mode');
        if ($mode === 'off') {
            return false;
        }
        if ($mode === 'always' || self::flagged() || self::active()) {
            return true;
        }

        // a signed header on the request being handled right now (checked here because the classes of the request are loaded
        // before the middleware that would look at it)
        $header = (string) $config->get('request-tracer.triggers.header', 'X-Request-Tracer');
        $server = 'HTTP_'.strtoupper(str_replace('-', '_', $header));
        $token = $_SERVER[$server] ?? null;
        if (! is_string($token) || $token === '' || ! App::container()->bound('request')) {
            return false;
        }
        $request = App::container()->make('request');

        return $request instanceof Request && App::container()->make(TokenService::class)->verify($token, $request) !== null;
    }
}
