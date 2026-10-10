<?php

declare(strict_types=1);

namespace RequestTracer\DeepTrace;

use RequestTracer\Support\Files;

/**
 * "Trace this file line by line" requests queued from the dashboard.
 *
 * The queue is a PHP file in the compiled-code directory (read once per process through OPcache), so the autoloader can
 * consult it without touching the cache or the database. An entry lives for `deep_trace.queue_ttl` seconds or until a recorded
 * request has used it. On a multi-server deployment point `deep_trace.cache_path` at shared storage or queue per server.
 */
final class DeepQueue
{
    /** @var array<string, int>|null path => expires at (unix) */
    private ?array $loaded = null;

    public function __construct(private readonly string $cachePath) {}

    public function file(): string
    {
        return rtrim($this->cachePath, '/\\').DIRECTORY_SEPARATOR.'queue.php';
    }

    /** @return array<string, int> */
    public function active(): array
    {
        if ($this->loaded === null) {
            $file = $this->file();
            $all = is_file($file) ? include $file : [];
            $now = time();
            $this->loaded = is_array($all) ? array_filter($all, static fn ($expires): bool => is_int($expires) && $expires > $now) : [];
        }

        return $this->loaded;
    }

    public function isEmpty(): bool
    {
        return $this->active() === [];
    }

    public function contains(string $absolutePath): bool
    {
        return isset($this->active()[$absolutePath]);
    }

    public function add(string $absolutePath, int $ttl): void
    {
        $queue = $this->read();
        $queue[$absolutePath] = time() + max(60, $ttl);
        $this->save($queue);
    }

    public function remove(string $absolutePath): void
    {
        $queue = $this->read();
        unset($queue[$absolutePath]);
        $this->save($queue);
    }

    /**
     * Called after a recorded request produced line data for these files.
     *
     * @param  list<string>  $absolutePaths
     */
    public function consume(array $absolutePaths): void
    {
        $queue = $this->read();
        $changed = false;
        foreach ($absolutePaths as $path) {
            if (isset($queue[$path])) {
                unset($queue[$path]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->save($queue);
        }
    }

    /** @return array<string, int> */
    public function read(): array
    {
        $this->loaded = null;
        $file = $this->file();
        if (function_exists('opcache_invalidate') && is_file($file)) {
            @opcache_invalidate($file, true);
        }
        $all = is_file($file) ? include $file : [];
        $now = time();

        return is_array($all) ? array_filter($all, static fn ($expires): bool => is_int($expires) && $expires > $now) : [];
    }

    /** @param  array<string, int>  $queue */
    private function save(array $queue): void
    {
        Files::write($this->file(), '<?php return '.var_export($queue, true).';'.PHP_EOL);
        $this->loaded = null;
    }
}
