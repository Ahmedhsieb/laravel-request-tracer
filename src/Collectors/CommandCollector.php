<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use RequestTracer\Recording\BackgroundDecider;
use RequestTracer\SpanType;
use RequestTracer\Support\App;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Artisan commands (`command` traces) and scheduled tasks that run inside the scheduler process (`schedule` traces).
 *
 * A scheduled task that is itself an artisan command runs in a child process and is recorded there as a command, so only
 * closures, invokable classes and `->job()` dispatches are recorded as `schedule` traces. `queue:work`, `schedule:run` and the
 * other long-running or internal commands are in `exclude.commands`; the jobs a worker runs are recorded by the JobCollector.
 */
final class CommandCollector implements Collector
{
    public static function key(): string
    {
        return 'commands';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        if (! $app->runningInConsole()) {
            return;
        }

        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(CommandStarting::class, function (CommandStarting $event) use ($tracer): void {
            Failure::guard(function () use ($event, $tracer): void {
                $name = (string) $event->command;
                if ($name === '' || $tracer->session() !== null) {
                    return;                                         // `Artisan::call()` inside another trace: part of that trace
                }
                $decision = App::container()->make(BackgroundDecider::class)->decide('command', $name);
                $session = $tracer->beginBackground('command', $name, SpanType::Command, $name, $decision, $this->input($event, $name));
                if ($session === null) {
                    return;
                }

                // a command that ends the process with exit() / a fatal error never reaches CommandFinished: store what we have
                $id = $session->id;
                register_shutdown_function(static function () use ($tracer, $id): void {
                    Failure::guard(static fn () => $tracer->endBackground('command', null, 'The process ended before the command finished', null, $id), 'command shutdown');
                });
            }, 'command starting');
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event) use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('command', $event->exitCode, 'Exit code '.$event->exitCode), 'command finished');
            }
        });

        $events->listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) use ($tracer): void {
            Failure::guard(function () use ($event, $tracer): void {
                if (! $event->task instanceof CallbackEvent || $tracer->session() !== null) {
                    return;
                }
                $name = $event->task->getSummaryForDisplay();
                $decision = App::container()->make(BackgroundDecider::class)->decide('schedule', $name);
                $tracer->beginBackground('schedule', $name, SpanType::Command, $name, $decision, [
                    'task' => $name,
                    'expression' => $event->task->expression,
                ]);
            }, 'scheduled task starting');
        });

        $events->listen(ScheduledTaskFinished::class, function () use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('schedule', 0, 'Finished'), 'scheduled task finished');
            }
        });

        $events->listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) use ($tracer): void {
            if ($tracer->on) {
                Failure::guard(fn () => $tracer->endBackground('schedule', 1, 'Failed', $event->exception), 'scheduled task failed');
            }
        });
    }

    /** @return array<string, mixed> */
    private function input(CommandStarting $event, string $name): array
    {
        try {
            return ['command' => $name, 'arguments' => $event->input->getArguments(), 'options' => $event->input->getOptions()];
        } catch (\Throwable) {
            return ['command' => $name];
        }
    }
}
