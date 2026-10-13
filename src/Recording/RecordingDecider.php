<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Recording;

use Ahmedhsieb\RequestTracer\RecordingState;
use Ahmedhsieb\RequestTracer\Replay\Replayer;
use Ahmedhsieb\RequestTracer\Support\App;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Decides, at the very start of a request, whether it is recorded (brief section 7).
 *
 * Order: master switch → mode → `always` mode → (triggered mode:) signed header → somebody is listening → watch list / sampling,
 * and the excluded paths win over every trigger.
 *
 * The header is valid immediately. The watch list and sampling need to know *who* the user is, which is only known after the
 * session middleware ran, so those requests are recorded "tentatively" (in memory) and confirmed when the user authenticates;
 * a request that never authenticates is dropped without a single write. A token that names a user (`uid`) behaves the same.
 *
 * Not final on purpose: bind a subclass in the container to change how requests are chosen.
 */
class RecordingDecider
{
    /** always | triggered | off. `triggered` (the default everywhere) records while somebody listens, or when a signed header, a watched user or a sample asks for it. */
    public function mode(): string
    {
        $mode = App::config()->get('request-tracer.mode');
        if (is_string($mode) && in_array($mode, ['always', 'triggered', 'off'], true)) {
            return $mode;
        }

        return 'triggered';
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
        if (Replayer::active()) {
            return new Decision(RecordingState::On, 'replay');      // asked for, so neither the excluded paths nor the mode apply
        }
        if ($mode === 'always') {
            return $this->excluded($request) ? Decision::off() : new Decision(RecordingState::On, 'always');
        }

        // Triggered mode: look for a trigger first (a header, a watch list, sampling). Without one the request is not recorded, and
        // the exclusion patterns (a regex per pattern) are never evaluated for the vast majority of requests.
        $decision = $this->triggered($request);

        return $decision->on() && $this->excluded($request) ? Decision::off() : $decision;
    }

    private function triggered(Request $request): Decision
    {
        $config = App::config();

        // (a) signed header
        $header = (string) $config->get('request-tracer.triggers.header', 'X-Request-Tracer');
        $token = $request->headers->get($header);
        if (is_string($token) && $token !== '') {
            $payload = App::container()->make(TokenService::class)->verify($token, $request);
            if ($payload !== null) {
                return isset($payload['uid'])
                    ? new Decision(RecordingState::Tentative, 'token', ['token_uid' => $payload['uid']])
                    : new Decision(RecordingState::On, 'token');
            }
        }

        // (b) somebody is running `php artisan request-tracer:listen`
        if (Listening::active()) {
            return new Decision(RecordingState::On, 'listen');
        }

        // (c) watch list and (d) sampling: both are confirmed once a user has authenticated
        $hints = [];
        if ($config->get('request-tracer.triggers.watch_list.enabled', true) && App::container()->make(WatchList::class)->any()) {
            $hints['watch'] = true;
        }
        if ($config->get('request-tracer.triggers.sampling.enabled', false) && Sampler::hit((float) $config->get('request-tracer.triggers.sampling.rate', 0.01))) {
            if ((bool) $config->get('request-tracer.triggers.sampling.require_auth', true)) {
                $hints['sample'] = true;
            } else {
                return new Decision(RecordingState::On, 'sample');
            }
        }

        if ($hints === []) {
            return Decision::off();
        }

        return new Decision(RecordingState::Tentative, isset($hints['watch']) ? 'watch' : 'sample', $hints);
    }

    public function excluded(Request $request): bool
    {
        $config = App::config();
        $path = ltrim($request->path(), '/');
        $subject = $path === '' ? '/' : $path;

        $patterns = (array) $config->get('request-tracer.exclude.paths', []);
        $own = trim((string) $config->get('request-tracer.dashboard.path', 'request-tracer'), '/');
        if ($own !== '') {
            $patterns[] = $own;
            $patterns[] = $own.'/*';
        }
        if (Str::is($patterns, $subject)) {
            return true;
        }

        $only = (array) $config->get('request-tracer.scope.paths', []);

        return $only !== [] && ! Str::is($only, $subject);
    }
}
