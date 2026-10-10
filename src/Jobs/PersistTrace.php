<?php

declare(strict_types=1);

namespace RequestTracer\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\TraceData;

/**
 * Persists a finished trace from a queue worker instead of in terminate() (`storage.queue.enabled`).
 * The payload is gzip+base64 JSON so large traces stay small in the queue.
 */
final class PersistTrace implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $payload) {}

    /** @param  array<string, mixed>  $queue  the `storage.queue` config section */
    public static function dispatchFor(TraceData $data, array $queue): void
    {
        $json = (string) json_encode($data->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $gz = function_exists('gzencode') ? gzencode($json, 6) : false;
        $job = new self($gz === false ? $json : 'gz:'.base64_encode($gz));

        if (! empty($queue['connection'])) {
            $job->onConnection((string) $queue['connection']);
        }
        if (! empty($queue['queue'])) {
            $job->onQueue((string) $queue['queue']);
        }

        dispatch($job);
    }

    public function handle(TraceStorage $storage): void
    {
        $json = $this->payload;
        if (str_starts_with($json, 'gz:')) {
            $raw = base64_decode(substr($json, 3), true);
            $json = $raw === false ? '' : (string) gzdecode($raw);
        }
        $array = json_decode($json, true);
        if (! is_array($array)) {
            return;
        }

        $storage->store(TraceData::fromArray($array));
    }
}
