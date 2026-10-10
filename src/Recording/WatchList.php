<?php

declare(strict_types=1);

namespace RequestTracer\Recording;

use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Repository;
use RequestTracer\Support\App;
use RequestTracer\Support\Failure;

/**
 * Users whose requests are recorded for a while ("watch this user for 30 minutes"), created from the dashboard.
 * Stored in the application cache as {userId: expiresAt}. Checking it costs one cache read per request, which is why
 * `triggers.watch_list.enabled` exists.
 */
final class WatchList
{
    private const KEY = 'request-tracer:watch-list';

    /** @return array<string, int> user id => expires at (unix), expired entries removed */
    public function entries(): array
    {
        try {
            $all = $this->store()->get(self::KEY, []);
        } catch (\Throwable $e) {
            Failure::report($e, 'reading the watch list');

            return [];
        }
        if (! is_array($all)) {
            return [];
        }
        $now = now()->getTimestamp();

        return array_filter($all, static fn ($expires): bool => is_int($expires) && $expires > $now);
    }

    public function any(): bool
    {
        return $this->entries() !== [];
    }

    public function contains(string|int $userId): bool
    {
        return isset($this->entries()[(string) $userId]);
    }

    public function add(string|int $userId, int $minutes): int
    {
        $max = (int) App::config()->get('request-tracer.triggers.watch_list.max_ttl', 3600);
        $seconds = max(60, min($max, $minutes * 60));
        $entries = $this->entries();
        $entries[(string) $userId] = now()->getTimestamp() + $seconds;
        $this->save($entries);

        return $entries[(string) $userId];
    }

    public function remove(string|int $userId): void
    {
        $entries = $this->entries();
        unset($entries[(string) $userId]);
        $this->save($entries);
    }

    /** @param  array<string, int>  $entries */
    private function save(array $entries): void
    {
        $longest = $entries === [] ? 60 : max($entries) - now()->getTimestamp() + 60;
        $this->store()->put(self::KEY, $entries, max(60, $longest));
    }

    private function store(): Repository
    {
        $name = App::config()->get('request-tracer.triggers.watch_list.store');
        /** @var CacheManager $cache */
        $cache = App::container()->make('cache');

        return is_string($name) && $name !== '' ? $cache->store($name) : $cache->store();
    }
}
