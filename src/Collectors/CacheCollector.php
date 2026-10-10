<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use RequestTracer\Dumper\MaskedValue;
use RequestTracer\SpanType;
use RequestTracer\Support\CallSite;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/** Cache hits, misses, writes and forgets as `cache` spans (identical ones collapse, so hot keys stay cheap). */
final class CacheCollector implements Collector
{
    public static function key(): string
    {
        return 'cache';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(CacheHit::class, fn (CacheHit $e) => $this->record($tracer, 'hit', $e->key, $e->value, true));
        $events->listen(CacheMissed::class, fn (CacheMissed $e) => $this->record($tracer, 'miss', $e->key, null, false));
        $events->listen(KeyWritten::class, fn (KeyWritten $e) => $this->record($tracer, 'write', $e->key, $e->value, true));
        $events->listen(KeyForgotten::class, fn (KeyForgotten $e) => $this->record($tracer, 'forget', $e->key, null, false));
    }

    private function record(Tracer $tracer, string $kind, string $key, mixed $value, bool $withValue): void
    {
        if (! $tracer->on) {
            return;
        }
        try {
            $session = $tracer->session();
            if ($session === null) {
                return;
            }
            [$file, $line] = CallSite::find();
            $span = $session->recorder->leaf(SpanType::Cache, 'cache '.$kind.' '.$key, 0, $file, $line);
            if ($span->ghost) {
                return;
            }
            $span->meta['kind'] = $kind;
            $span->input = $session->dump(['key' => $key]);
            if ($withValue) {
                $span->output = $session->dump($tracer->masker()->isSensitiveKey($key) ? new MaskedValue : $value);
            }
        } catch (\Throwable $e) {
            Failure::report($e, 'cache collector');
        }
    }
}
