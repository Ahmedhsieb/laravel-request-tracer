<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use Composer\Autoload\ClassLoader;
use RequestTracer\Recording\Listening;
use RequestTracer\Support\App;
use RequestTracer\Support\Brand;
use RequestTracer\Support\ClassPatterns;
use RequestTracer\Support\CodeScope;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Files;
use RequestTracer\Support\Paths;

/**
 * Prepended autoloader that swaps selected classes for a compiled copy with probes (the way Blade compiles views).
 *
 * A class is a candidate when it matches `deep_trace.namespaces`, lives in a file listed in `deep_trace.files`, was queued from
 * the dashboard ("Trace this file line by line"), or (opt-in, `attribute_scan`) carries `#[Trace(deep: true)]`. The original file
 * is found through Composer's ClassLoader, compiled once (cached by source mtime + compiler/package version) and `require`d
 * instead. If the file cannot be compiled, the original is loaded untouched and a warning is kept for the dashboard.
 *
 * Limits: a class that was already loaded before this autoloader ran (providers, anything touched while the app boots) is not
 * compiled; in a long-lived worker a file queued later only takes effect after the worker restarts.
 */
final class Loader
{
    public const PACKAGE_VERSION = '0.3.0';

    /** @var array<string, string> compiled file => original file (process-wide, constant once set) */
    private static array $origins = [];

    /** @var array<string, string> original file => warning (until a trace picks them up) */
    private static array $warnings = [];

    private static ?self $instance = null;

    /** @var array<string, true> */
    private array $seen = [];

    /** @var array<string, DeepQueue> */
    private array $queues = [];

    public function __construct(private readonly Compiler $compiler) {}

    /** Register the (single, process-wide) autoloader. It reads its settings from the current config on every call. */
    public static function ensureRegistered(): void
    {
        if (self::$instance === null) {
            self::$instance = new self(new Compiler);
            // Run the autoloader's own code once before it is registered: the classes it uses (config repository, Arr, ClassPatterns,
            // DeepQueue...) are then loaded the normal way. Loaded for the first time from inside the autoloader they would fail,
            // because PHP does not re-enter the autoloader for a class that is already being autoloaded.
            self::$instance->autoload('RequestTracerLoaderWarmUp');
            Failure::guard(static function (): void {
                CodeScope::includesFile(__FILE__);
                CodeScope::classAllowed('RequestTracerLoaderWarmUp');
                Listening::wanted();
            }, 'warming up the deep trace loader');
            self::$instance->register();
        }
    }

    public static function cachePath(): string
    {
        $path = App::config()->get('request-tracer.deep_trace.cache_path');

        return is_string($path) && $path !== '' ? rtrim($path, '/\\') : App::application()->storagePath('framework/'.Brand::STORAGE_DIR.'/compiled');
    }

    public static function queue(): DeepQueue
    {
        return self::$instance?->queueFor(self::cachePath()) ?? new DeepQueue(self::cachePath());
    }

    public function register(): void
    {
        spl_autoload_register(function (string $class): void {
            $this->autoload($class);
        }, true, true);
    }

    public function autoload(string $class): void
    {
        if (isset($this->seen[$class])) {
            return;
        }
        // This loader sits in front of Composer's, so it is asked for every class, including the ones it needs itself (ClassPatterns,
        // DeepQueue, Paths, the php-parser). PHP refuses to re-enter the autoloader for a class that is being autoloaded, so touching
        // one of those classes from in here would fail with "class not found" the first time. They are never traced: step aside.
        if ((str_starts_with($class, 'RequestTracer\\') && ! str_starts_with($class, 'RequestTracer\\Tests\\')) || str_starts_with($class, 'PhpParser\\')) {
            return;
        }
        $this->seen[$class] = true;

        try {
            $config = App::config();
            if (! $config->get('request-tracer.enabled', true) || ! $config->get('request-tracer.deep_trace.enabled', true)) {
                return;
            }
            $namespaces = (array) $config->get('request-tracer.deep_trace.namespaces', []);
            $files = array_map(static fn ($f): string => Paths::normalize((string) (realpath((string) $f) ?: $f)), array_values((array) $config->get('request-tracer.deep_trace.files', [])));
            $attributeNamespaces = array_values(array_filter((array) $config->get('request-tracer.proxies.attribute_namespaces', []), 'is_string'));
            $scan = (bool) $config->get('request-tracer.deep_trace.attribute_scan', false) && $this->startsWithAny($class, $attributeNamespaces);
            $queue = $this->queueFor(self::cachePath());

            $byNamespace = $namespaces !== [] && ClassPatterns::matches($class, $namespaces);
            $queued = ! $queue->isEmpty();
            // the project's own code (`code.paths`, default: app/) is compiled while somebody is listening, or the process was told to trace
            $scoped = (bool) $config->get('request-tracer.code.line_by_line', true) && Listening::wanted();
            if (! $byNamespace && $files === [] && ! $queued && ! $scan && ! $scoped) {
                return;
            }

            $file = $this->findFile($class);
            if ($file === null) {
                return;
            }
            $real = Paths::normalize(realpath($file) ?: $file);

            $wanted = $byNamespace
                || ($scoped && CodeScope::includesFile($real) && CodeScope::classAllowed($class))
                || ($files !== [] && in_array($real, $files, true))
                || ($queued && $queue->contains($real))
                || ($scan && $this->hasDeepAttribute($real));
            if ($wanted) {
                $this->load($real);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'deep trace loader ('.$class.')');
        }
    }

    private function queueFor(string $cachePath): DeepQueue
    {
        return $this->queues[$cachePath] ??= new DeepQueue($cachePath);
    }

    /** The original file behind a compiled one (so stack traces and spans show the file the developer edits). */
    public static function originalOf(string $file): ?string
    {
        return self::$origins[str_replace('\\', '/', $file)] ?? self::$origins[$file] ?? null;
    }

    /**
     * Warnings produced since the last call ("could not compile X: ..."). A trace shows the ones that happened in its process.
     *
     * @return array<string, string> file => message
     */
    public static function pullWarnings(): array
    {
        $warnings = self::$warnings;
        self::$warnings = [];

        return $warnings;
    }

    private function load(string $real): void
    {
        $key = sha1($real);
        $dir = self::cachePath();
        $compiled = $dir.'/'.$key.'.php';
        $metaFile = $dir.'/'.$key.'.meta.php';
        $mtime = @filemtime($real);
        $version = Compiler::VERSION.'/'.self::PACKAGE_VERSION;

        $meta = is_file($metaFile) ? (include $metaFile) : null;
        $fresh = is_array($meta) && ($meta['mtime'] ?? null) === $mtime && ($meta['version'] ?? null) === $version;

        if ($fresh && $meta['status'] === 'ok' && is_file($compiled)) {
            $this->include($compiled, $real, $metaFile, $version, $mtime);

            return;
        }
        if ($fresh && $meta['status'] === 'failed') {
            self::warn($real, (string) ($meta['error'] ?? 'unknown error'));

            return;
        }

        $source = (string) file_get_contents($real);
        $relative = Paths::relative($real) ?? $real;
        try {
            $result = $this->compiler->compile($source, $relative, $real);
            Files::write($compiled, $result->code);
            Files::write(ProbeMap::fileName($dir, $result->fileKey), '<?php return '.var_export(['path' => $relative, 'probes' => $result->probes], true).';'.PHP_EOL);
            Files::write($metaFile, '<?php return '.var_export(['status' => 'ok', 'mtime' => $mtime, 'version' => $version, 'probes' => count($result->probes)], true).';'.PHP_EOL);
        } catch (\Throwable $e) {
            self::markFailed($metaFile, $mtime, $version, $e->getMessage());
            self::warn($real, $e->getMessage());

            return;
        }

        $this->include($compiled, $real, $metaFile, $version, $mtime);
    }

    private function include(string $compiled, string $real, string $metaFile, string $version, int|false $mtime): void
    {
        self::$origins[Paths::normalize($compiled)] = $real;
        try {
            require $compiled;
        } catch (\ParseError|\CompileError $e) {
            // never expected (the compiled code was parse-checked) but a bad copy must not take the application down
            unset(self::$origins[Paths::normalize($compiled)]);
            self::markFailed($metaFile, $mtime, $version, $e->getMessage());
            self::warn($real, $e->getMessage());
        }
    }

    /** Remember that a file does not compile so it is not retried on every request. Best effort: an unwritable cache must not hide the real problem. */
    private static function markFailed(string $metaFile, int|false $mtime, string $version, string $error): void
    {
        try {
            Files::write($metaFile, '<?php return '.var_export(['status' => 'failed', 'mtime' => $mtime, 'version' => $version, 'error' => $error], true).';'.PHP_EOL);
        } catch (\Throwable) {
            // nothing else to do: the original file is used either way
        }
    }

    private static function warn(string $file, string $message): void
    {
        if (! isset(self::$warnings[$file])) {
            self::$warnings[$file] = $message;
            Failure::report(new \RuntimeException("Deep trace could not compile {$file}: {$message}"), 'deep trace');
        }
    }

    private function findFile(string $class): ?string
    {
        foreach (ClassLoader::getRegisteredLoaders() as $loader) {
            $file = $loader->findFile($class);
            if (is_string($file)) {
                return $file;
            }
        }

        return null;
    }

    private function hasDeepAttribute(string $file): bool
    {
        $source = (string) @file_get_contents($file);
        if (! str_contains($source, 'Trace') || ! str_contains($source, 'deep')) {
            return false;
        }

        return $this->compiler->attributeHints($source)['deep'];
    }

    /** @param  list<string>  $prefixes */
    private function startsWithAny(string $class, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
