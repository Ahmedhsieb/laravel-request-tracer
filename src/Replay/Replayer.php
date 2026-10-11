<?php

declare(strict_types=1);

namespace RequestTracer\Replay;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RequestTracer\Storage\ConnectionResolver;
use RequestTracer\Support\App;
use RequestTracer\Tracer;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a request that already happened again, traced ("what did this request do?").
 *
 * The application's own HTTP kernel handles it, as if it had arrived from outside, so every middleware, queue and event runs.
 * To make that safe to do on purpose:
 *
 *  - database changes are rolled back (a transaction on every connection the request touches);
 *  - e-mails go to the `array` mailer, queued jobs are discarded, broadcasts are dropped;
 *  - what cannot be taken back is calls to other services (HTTP client, payment gateways...): they are real. Replay is therefore off in
 *    the production environment unless `request-tracer.replay.enabled` is true.
 *
 * `runInChild()` does it in a PHP process of its own, which is what the dashboard and the command use: the code is then compiled for
 * line-by-line tracing from the very first class, which a process that has served other requests cannot do.
 */
class Replayer
{
    public const RESULT_PREFIX = 'RT-REPLAY-RESULT: ';

    private static bool $active = false;

    /** True while a replay is running: the recording decision says yes to it, whatever the mode and the excluded paths are. */
    public static function active(): bool
    {
        return self::$active;
    }

    public static function allowed(): bool
    {
        $setting = App::config()->get('request-tracer.replay.enabled');
        if (is_bool($setting)) {
            return $setting;
        }

        return ! App::application()->environment('production');
    }

    /**
     * Run the request in this process.
     *
     * @return array{trace_id: string|null, status: int|null, error: string|null}
     */
    public function run(ReplayRequest $replay): array
    {
        if (! self::allowed()) {
            return ['trace_id' => null, 'status' => null, 'error' => 'Replay is switched off here (request-tracer.replay.enabled).'];
        }

        self::$active = true;
        $transactions = new ReplayTransactions;
        $this->forgetUsers();
        try {
            $this->makeSideEffectsHarmless();
            $request = $replay->toRequest();
            $this->prepareSession($request);
            if ($replay->asUser !== null) {
                $error = $this->actAs($replay->asUser);
                if ($error !== null) {
                    return ['trace_id' => null, 'status' => null, 'error' => $error];
                }
            }

            $transactions->begin();
            $kernel = App::container()->make(Kernel::class);
            $tracer = App::container()->make(Tracer::class);
            $tracer->lastStoredId = null;

            $response = $kernel->handle($request);
            // When the trace is written on the very connection the request used (in-memory SQLite), undo the request first, or the trace goes with it.
            if ($transactions->holds(App::container()->make(ConnectionResolver::class)->resolve())) {
                $transactions->rollBack();
                $kernel->terminate($request, $response);
            } else {
                $kernel->terminate($request, $response);
                $transactions->rollBack();
            }

            return ['trace_id' => $tracer->lastStoredId, 'status' => $response->getStatusCode(), 'error' => null];
        } catch (\Throwable $e) {
            return ['trace_id' => null, 'status' => null, 'error' => $e::class.': '.$e->getMessage()];
        } finally {
            $transactions->rollBack();
            $this->forgetUsers();
            self::$active = false;
        }
    }

    /** Nobody stays logged in from one replay to the next (the guards remember the user they were given). */
    private function forgetUsers(): void
    {
        $auth = Auth::getFacadeRoot();
        if (is_object($auth) && method_exists($auth, 'forgetGuards')) {
            $auth->forgetGuards();
        }
    }

    /**
     * Run the request in a new PHP process (`php artisan request-tracer:replay`, with `REQUEST_TRACER=1` so the code is traced from the start).
     *
     * @return array{trace_id: string|null, status: int|null, error: string|null}
     */
    public function runInChild(ReplayRequest $replay): array
    {
        $file = tempnam(sys_get_temp_dir(), 'rt-replay-');
        if ($file === false) {
            return ['trace_id' => null, 'status' => null, 'error' => 'Could not create a temporary file for the replay.'];
        }

        try {
            file_put_contents($file, (string) json_encode($replay->toArray()));
            $php = (new PhpExecutableFinder)->find(false) ?: PHP_BINARY;
            $process = new Process(
                [$php, App::application()->basePath('artisan'), 'request-tracer:replay', '--payload-file='.$file, '--json', '--no-interaction'],
                App::application()->basePath(),
                ['REQUEST_TRACER' => '1'],
                null,
                (float) App::config()->get('request-tracer.replay.timeout', 120),
            );
            $process->run();

            foreach (array_reverse(preg_split('/\R/', $process->getOutput()) ?: []) as $line) {
                if (str_starts_with($line, self::RESULT_PREFIX)) {
                    $decoded = json_decode(substr($line, strlen(self::RESULT_PREFIX)), true);
                    if (is_array($decoded)) {
                        return [
                            'trace_id' => is_string($decoded['trace_id'] ?? null) ? $decoded['trace_id'] : null,
                            'status' => is_int($decoded['status'] ?? null) ? $decoded['status'] : null,
                            'error' => is_string($decoded['error'] ?? null) ? $decoded['error'] : null,
                        ];
                    }
                }
            }

            $output = trim($process->getErrorOutput()."\n".$process->getOutput());

            return ['trace_id' => null, 'status' => null, 'error' => $process->isSuccessful() ? 'The replay produced no result.' : 'The replay process failed: '.mb_substr($output, -600)];
        } finally {
            @unlink($file);
        }
    }

    /** Mail goes nowhere, queued jobs are dropped, broadcasts are not sent. */
    private function makeSideEffectsHarmless(): void
    {
        $config = App::config();
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('mail.default', 'array');
        $config->set('queue.connections.request-tracer-discard', ['driver' => 'null']);
        $config->set('queue.default', 'request-tracer-discard');
        $config->set('broadcasting.connections.null', ['driver' => 'null']);
        $config->set('broadcasting.default', 'null');
        // the trace must survive the rollback of the request
        $config->set('request-tracer.storage.separate_connection', true);
    }

    /**
     * A POST would be refused for a missing CSRF token. Give the request a session with a token it carries, unless it brought its own.
     */
    private function prepareSession(Request $request): void
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $request->headers->has('X-CSRF-TOKEN') || $request->headers->has('X-XSRF-TOKEN') || $request->has('_token')) {
            return;
        }

        try {
            $store = App::container()->make('session')->driver();
            $store->start();
            $store->regenerateToken();
            $request->headers->set('X-CSRF-TOKEN', (string) $store->token());
        } catch (\Throwable) {
            // an application without sessions has no CSRF check to satisfy
        }
    }

    /** @return string|null an error message when the user cannot be found */
    private function actAs(string|int $id): ?string
    {
        $config = App::config();
        $defaultGuard = (string) $config->get('auth.defaults.guard', 'web');
        $providerName = $config->get('auth.guards.'.$defaultGuard.'.provider');
        $provider = is_string($providerName) ? Auth::createUserProvider($providerName) : null;
        $user = $provider?->retrieveById($id);
        if (! $user instanceof Authenticatable) {
            return 'There is no user with id '.$id.' (guard "'.$defaultGuard.'").';
        }

        foreach (array_keys((array) $config->get('auth.guards', [])) as $name) {
            try {
                $guard = Auth::guard((string) $name);
                if (method_exists($guard, 'setUser')) {
                    $guard->setUser($user);
                }
            } catch (\Throwable) {
                // a guard that cannot be built here (a missing driver) is not one the request can use
            }
        }

        return null;
    }
}
