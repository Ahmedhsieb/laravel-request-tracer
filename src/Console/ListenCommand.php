<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Console;

use Ahmedhsieb\RequestTracer\Recording\Listening;
use Ahmedhsieb\RequestTracer\Recording\RecordingDecider;
use Ahmedhsieb\RequestTracer\Storage\TableInstaller;
use Ahmedhsieb\RequestTracer\Storage\TraceStorage;
use Ahmedhsieb\RequestTracer\Support\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

/**
 * `php artisan request-tracer:listen`, the `queue:work` of tracing.
 *
 * While it runs, everything this project does is traced: requests, queued jobs, artisan commands and scheduled tasks, with the
 * code of `code.paths` looked into line by line. Each trace is printed here as soon as it is stored. Stop it (Ctrl+C) and recording stops
 * within a few seconds; nothing else is switched on or off, and nothing needs cleaning up.
 */
class ListenCommand extends Command
{
    protected $signature = 'request-tracer:listen
                            {--seconds= : Stop after this many seconds (default: run until you stop it)}
                            {--no-feed : Do not print the traces as they arrive}';

    protected $description = 'Trace everything this project does while this command runs';

    private bool $stopping = false;

    public function handle(TraceStorage $storage, RecordingDecider $decider): int
    {
        if (! (bool) config(Brand::NAME.'.enabled', true)) {
            $this->components->error('Request Tracer is switched off (request-tracer.enabled is false).');

            return self::FAILURE;
        }
        if ($decider->mode() === 'off') {
            $this->components->error('Recording is switched off (request-tracer.mode is "off").');

            return self::FAILURE;
        }

        TableInstaller::ensure();

        $this->components->info('Listening. Everything this project does is traced until you stop this command (Ctrl+C).');
        $this->line('  Dashboard: <fg=cyan>'.$this->dashboardUrl().'</>');
        $this->warnAboutTheGate($decider);
        $this->line('');

        $this->trapSignals();
        register_shutdown_function(static fn () => Listening::stop());

        $until = $this->option('seconds') !== null ? microtime(true) + max(0.0, (float) $this->option('seconds')) : null;
        $lastSeen = $this->newestId($storage);
        $seen = 0;

        while (! $this->stopping && ($until === null || microtime(true) < $until)) {
            Listening::beat();
            if (! $this->option('no-feed')) {
                $seen += $this->printNewTraces($storage, $lastSeen);
            }
            $this->sleep();
        }

        Listening::stop();
        $this->line('');
        $this->components->info($seen === 0 ? 'Stopped. Nothing was traced while listening.' : "Stopped. {$seen} trace(s) were stored; open the dashboard to look inside them.");

        return self::SUCCESS;
    }

    /** One second between heartbeats (a method so a test can make it shorter). */
    protected function sleep(): void
    {
        usleep(1_000_000);
    }

    private function printNewTraces(TraceStorage $storage, ?string &$lastSeen): int
    {
        $rows = array_reverse($storage->traces([], null, 50)['data']);
        $printed = 0;
        foreach ($rows as $row) {
            $id = (string) $row['id'];
            if ($lastSeen !== null && strcmp($id, $lastSeen) <= 0) {
                continue;
            }
            $lastSeen = $id;
            $printed++;
            $this->line($this->describe($row));
        }

        return $printed;
    }

    /** @param  array<string, mixed>  $row */
    private function describe(array $row): string
    {
        $kind = (string) ($row['kind'] ?? 'request');
        $what = $kind === 'request' ? trim(($row['method'] ?? '').' '.($row['path'] ?? '')) : $kind.' '.($row['path'] ?? '');
        $status = $row['status'] ?? null;
        $problems = (int) ($row['problems_count'] ?? 0);
        $when = date('H:i:s');

        $line = sprintf(
            '  <fg=gray>%s</>  %s%s  <fg=gray>%s ms · %d steps · %d queries</>',
            $when,
            $what,
            $status === null ? '' : '  '.$status,
            rtrim(rtrim(number_format((float) ($row['duration_ms'] ?? 0), 1, '.', ''), '0'), '.'),
            (int) ($row['spans_count'] ?? 0),
            (int) ($row['queries_count'] ?? 0),
        );
        if ($problems > 0) {
            $line .= sprintf('  <fg=yellow>%d problem%s</>', $problems, $problems === 1 ? '' : 's');
        }

        return $line.'  <fg=gray>'.$this->dashboardUrl().'/trace/'.$row['id'].'</>';
    }

    private function newestId(TraceStorage $storage): ?string
    {
        $rows = $storage->traces([], null, 1)['data'];

        return $rows === [] ? null : (string) $rows[0]['id'];
    }

    private function dashboardUrl(): string
    {
        $base = rtrim((string) config('app.url', 'http://localhost'), '/');

        return $base.'/'.trim((string) config(Brand::NAME.'.dashboard.path', Brand::NAME), '/');
    }

    private function warnAboutTheGate(RecordingDecider $decider): void
    {
        if ($decider->isLocal() || Gate::has((string) config(Brand::NAME.'.dashboard.gate', Brand::GATE))) {
            return;
        }
        $this->newLine();
        $this->components->warn('This is not a local environment, so nobody can open the dashboard until you say who may. Add to a service provider:');
        $this->line("    Gate::define('".Brand::GATE."', fn (\$user) => in_array(\$user->email, ['you@example.com']));");
    }

    /** Let Ctrl+C end the loop cleanly where the platform allows it; elsewhere the heartbeat simply goes stale within seconds. */
    private function trapSignals(): void
    {
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, function (): void {
                    $this->stopping = true;
                });
            }
        } elseif (function_exists('sapi_windows_set_ctrl_handler')) {
            sapi_windows_set_ctrl_handler(function (): void {
                $this->stopping = true;
            });
        }
    }
}
