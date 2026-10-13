<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Console;

use Ahmedhsieb\RequestTracer\Journal\Journal;
use Ahmedhsieb\RequestTracer\Replay\UndoLog;
use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

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

        $expired = Failure::guard(static fn (): int => UndoLog::prune(), 'pruning the undo logs', 0);
        if ($expired > 0) {
            $this->components->info(sprintf('Deleted %d expired undo log%s.', $expired, $expired === 1 ? '' : 's'));
        }

        if ((bool) config('request-tracer.journal.enabled', false)) {
            $trimmed = Failure::guard(static fn (): int => $journal->trim(), 'trimming the journal', 0);
            $this->components->info(sprintf('Trimmed %d journal entr%s.', $trimmed, $trimmed === 1 ? 'y' : 'ies'));
        }

        return self::SUCCESS;
    }
}
