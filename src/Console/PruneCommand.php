<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use RequestTracer\Journal\Journal;
use RequestTracer\Storage\TraceStorage;
use RequestTracer\Support\Failure;

/** Deletes old traces: by age (`retention.hours`) and by count (`retention.max_rows`). Scheduled automatically when the tracer is enabled. */
final class PruneCommand extends Command
{
    protected $signature = 'request-tracer:prune
                            {--hours= : Delete traces older than this many hours (default: retention.hours)}
                            {--max= : Keep at most this many traces, newest first (default: retention.max_rows, 0 = unlimited)}';

    protected $description = 'Delete old Request Tracer traces';

    public function handle(TraceStorage $storage, Journal $journal): int
    {
        $hours = $this->option('hours') !== null ? (float) $this->option('hours') : (float) config('request-tracer.retention.hours', 24);
        $max = $this->option('max') !== null ? (int) $this->option('max') : (int) config('request-tracer.retention.max_rows', 5000);

        $deleted = $storage->prune(Carbon::now()->subSeconds((int) round($hours * 3600)), max(0, $max));

        $this->components->info(sprintf('Pruned %d trace%s (older than %s hours%s). %d remain.', $deleted, $deleted === 1 ? '' : 's', rtrim(rtrim(number_format($hours, 2, '.', ''), '0'), '.'), $max > 0 ? ", max {$max}" : '', $storage->count()));

        if ((bool) config('request-tracer.journal.enabled', false)) {
            $trimmed = Failure::guard(static fn (): int => $journal->trim(), 'trimming the journal', 0);
            $this->components->info(sprintf('Trimmed %d journal entr%s.', $trimmed, $trimmed === 1 ? 'y' : 'ies'));
        }

        return self::SUCCESS;
    }
}
