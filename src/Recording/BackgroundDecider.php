<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Recording;

use Ahmedhsieb\RequestTracer\RecordingState;
use Ahmedhsieb\RequestTracer\Support\App;
use Illuminate\Support\Str;

/**
 * Decides whether a queued job, an artisan command or a scheduled task is recorded.
 *
 * - `always` mode records everything that is not excluded.
 * - `triggered` mode (the production default) records a job only when the trace that dispatched it was recorded
 *   (the queue payload carries `request_tracer.trace`), and a command or scheduled task only when the process was
 *   started with the environment variable `REQUEST_TRACER=1`. Nothing else is ever recorded in production.
 * - `exclude.commands` / `exclude.jobs` (fnmatch patterns) always win.
 */
class BackgroundDecider
{
    public function __construct(private readonly RecordingDecider $decider) {}

    /**
     * @param  string  $kind  job | command | schedule
     * @param  bool  $stamped  the job was dispatched while a trace was being recorded
     */
    public function decide(string $kind, string $name, bool $stamped = false): Decision
    {
        $config = App::config();
        if (! $config->get('request-tracer.enabled', true) || ! $config->get('request-tracer.collectors.'.($kind === 'job' ? 'jobs' : 'commands'), true)) {
            return Decision::off();
        }

        $mode = $this->decider->mode();
        if ($mode === 'off' || $this->excluded($kind, $name)) {
            return Decision::off();
        }
        if ($mode === 'always') {
            return new Decision(RecordingState::On, $kind);
        }

        if (Listening::active()) {
            return new Decision(RecordingState::On, $kind);
        }
        if ($kind === 'job') {
            return $stamped ? new Decision(RecordingState::On, 'job') : Decision::off();
        }

        return Listening::flagged() ? new Decision(RecordingState::On, $kind) : Decision::off();
    }

    public function excluded(string $kind, string $name): bool
    {
        if ($kind === 'job' && str_starts_with($name, 'Ahmedhsieb\\RequestTracer\\Jobs\\')) {
            return true;                                            // the tracer never traces its own queued writes
        }

        $patterns = (array) App::config()->get('request-tracer.exclude.'.($kind === 'job' ? 'jobs' : 'commands'), []);

        return $patterns !== [] && Str::is($patterns, $name);
    }
}
