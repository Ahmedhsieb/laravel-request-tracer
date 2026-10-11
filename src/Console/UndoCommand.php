<?php

declare(strict_types=1);

namespace RequestTracer\Console;

use Illuminate\Console\Command;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\UndoLog;

/**
 * `php artisan request-tracer:undo <trace id>`: take back what a replay that ran for real (`request-tracer:replay --real`) changed.
 * Only Eloquent writes were recorded exactly; the command says what it cannot undo.
 */
final class UndoCommand extends Command
{
    protected $signature = 'request-tracer:undo
                            {trace : The id of the trace of the replay}
                            {--force : Undo a row even when it changed after the replay}
                            {--yes : Do not ask}';

    protected $description = 'Undo the database changes of a replay that ran for real';

    public function handle(): int
    {
        if (! Replayer::allowed()) {
            $this->components->error('Replay is switched off in this environment (request-tracer.replay.enabled).');

            return self::FAILURE;
        }

        $argument = $this->argument('trace');
        $trace = is_string($argument) ? $argument : '';
        $summary = UndoLog::summary($trace);
        if ($summary === null || ! $summary['available']) {
            $this->components->error('There is nothing to undo for this trace (it was not run for real, nothing was recorded, the log expired, or it was undone already).');

            return self::FAILURE;
        }

        foreach ($summary['tables'] as $table => $operations) {
            $this->line(sprintf('  %s: %s', $table, implode(', ', array_map(static fn (string $op, int $n): string => $n.' '.$op, array_keys($operations), $operations))));
        }
        if ($summary['uncovered'] !== []) {
            $this->components->warn('Cannot be undone (query builder or raw SQL): '.implode(', ', array_map(static fn (string $t, int $n): string => $t.' ('.$n.')', array_keys($summary['uncovered']), $summary['uncovered'])));
        }
        if (! $this->option('yes') && ! $this->confirm('Undo these changes?', false)) {
            return self::FAILURE;
        }

        $result = UndoLog::apply($trace, (bool) $this->option('force'));
        if ($result['error'] !== null) {
            $this->components->error($result['error']);

            return self::FAILURE;
        }
        if (! $result['applied']) {
            $this->components->warn('Nothing was changed: some rows are not as the replay left them. Use --force to undo them anyway.');
            foreach ($result['conflicts'] as $conflict) {
                $this->line(sprintf('  %s #%s (%s): %s', $conflict['table'], $conflict['key'], $conflict['operation'], $conflict['reason']));
            }

            return self::FAILURE;
        }

        $this->components->info(sprintf('Undone: %d change%s.', $result['undone'], $result['undone'] === 1 ? '' : 's'));

        return self::SUCCESS;
    }
}
