<?php

declare(strict_types=1);

namespace RequestTracer\Recording;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RequestTracer\RecordingState;
use RequestTracer\Support\App;

/**
 * Decides, at the very start of a request, whether it is recorded (brief section 7).
 *
 * Order: master switch → mode → excluded paths → local environment / `always` → signed header → watch list / sampling (tentative).
 */
final class RecordingDecider
{
    /** always | triggered | off */
    public function mode(): string
    {
        $mode = App::config()->get('request-tracer.mode');
        if (is_string($mode) && in_array($mode, ['always', 'triggered', 'off'], true)) {
            return $mode;
        }

        return $this->isLocal() ? 'always' : 'triggered';
    }

    public function isLocal(): bool
    {
        $envs = (array) App::config()->get('request-tracer.local_environments', ['local']);

        return App::application()->environment(...$envs);
    }

    public function decide(Request $request): Decision
    {
        if (! App::config()->get('request-tracer.enabled', true)) {
            return Decision::off();
        }
        $mode = $this->mode();
        if ($mode === 'off') {
            return Decision::off();
        }
        if ($this->excluded($request)) {
            return Decision::off();
        }
        if ($mode === 'always') {
            return new Decision(RecordingState::On, 'always');
        }

        // Triggered mode: signed header, watch list and sampling arrive in phase 4.
        return Decision::off();
    }

    public function excluded(Request $request): bool
    {
        $config = App::config();
        $path = ltrim($request->path(), '/');
        $patterns = (array) $config->get('request-tracer.exclude.paths', []);
        if ($patterns !== [] && Str::is($patterns, $path === '' ? '/' : $path)) {
            return true;
        }

        $only = (array) $config->get('request-tracer.scope.paths', []);
        if ($only !== [] && ! Str::is($only, $path === '' ? '/' : $path)) {
            return true;
        }

        return false;
    }
}
