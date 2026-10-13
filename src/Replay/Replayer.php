<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Replay;

use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs a request that already happened again, traced ("what did this request do?").
 *
 * The application's own HTTP kernel handles it, as if it had arrived from outside, so every middleware, queue and event runs. Two modes:
 *
 *  - `simulate` (the default) changes nothing: database changes are rolled back (a transaction on every connection the request touches),
 *    e-mails go to the `array` mailer, notifications and broadcasts are dropped, queued jobs are discarded, calls made with Laravel's HTTP
 *    client are answered with an empty 200, the cache and the session live in memory.
 *  - `real` lets the request do what it does: committed changes, real e-mails, jobs and calls. Every Eloquent write is recorded exactly
 *    (see `UndoLog`) so that what can be undone can be taken back afterwards.
 *
 * What neither mode can control is anything outside the framework: files, a Guzzle or curl call made directly, an SDK. Replay is off in
 * the production environment unless `request-tracer.replay.enabled` is true, and real mode there needs `replay.real` too.
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

    /** May a replay run for real (changes are kept, mail is sent)? `replay.real`: null = wherever replay is allowed except production. */
    public static function realAllowed(): bool
    {
        if (! self::allowed()) {
            return false;
        }
        $setting = App::config()->get('request-tracer.replay.real');
        if (is_bool($setting)) {
            return $setting;
        }

        return ! App::application()->environment('production');
    }

    /**
     * Run the request in this process.
     *
     * @return array{trace_id: string|null, status: int|null, error: string|null, mode?: string, undo?: array<string, mixed>|null}
     */
    public function run(ReplayRequest $replay): array
    {
        if (! self::allowed()) {
            return ['trace_id' => null, 'status' => null, 'error' => 'Replay is switched off here (request-tracer.replay.enabled).'];
        }
        if ($replay->isReal() && ! self::realAllowed()) {
            return ['trace_id' => null, 'status' => null, 'error' => 'Running a replay for real is switched off here (request-tracer.replay.real).'];
        }

        $real = $replay->isReal();
        self::$active = true;
        $transactions = new ReplayTransactions;
        $this->forgetUsers();
        $logging = false;
        try {
            // the trace must survive the rollback of the request
            App::config()->set('request-tracer.storage.separate_connection', true);
            if (! $real) {
                $this->makeSideEffectsHarmless();
            }
            $request = $replay->toRequest();
            $this->prepareSession($request);
            if ($replay->asUser !== null) {
                $error = $this->actAs($replay->asUser);
                if ($error !== null) {
                    return ['trace_id' => null, 'status' => null, 'error' => $error];
                }
            }

            if ($real) {
                UndoLog::begin();
                $logging = true;
            } else {
                $transactions->begin();
            }
            $kernel = App::container()->make(Kernel::class);
            $tracer = App::container()->make(Tracer::class);
            $tracer->lastStoredId = null;

            $response = $kernel->handle($request);
            $log = $logging ? UndoLog::finish() : null;
            $logging = false;
            // Undo the request *before* terminate() stores the trace: with SQLite (one writer at a time) or in-memory databases the
            // trace's own write would otherwise wait for, or be thrown away with, the transaction of the request. Work the application
            // defers to the end of the request therefore runs after the rollback.
            $transactions->rollBack();
            $kernel->terminate($request, $response);

            $stored = $this->storedId($tracer);
            $undo = $log !== null && $stored !== null ? UndoLog::save($stored, $log) : null;
            if ($log !== null && $undo === null && $log['uncovered'] !== []) {
                $undo = ['available' => false, 'entries' => 0, 'tables' => [], 'uncovered' => $log['uncovered'], 'expires_at' => null];
            }

            return ['trace_id' => $stored, 'status' => $response->getStatusCode(), 'error' => null, 'mode' => $replay->mode, 'undo' => $undo];
        } catch (\Throwable $e) {
            return ['trace_id' => null, 'status' => null, 'error' => $e::class.': '.$e->getMessage()];
        } finally {
            if ($logging) {
                UndoLog::finish();
            }
            $transactions->rollBack();
            $this->forgetUsers();
            self::$active = false;
        }
    }

    /** The id the tracer stored the replay's trace under, null when nothing was stored. */
    private function storedId(Tracer $tracer): ?string
    {
        return $tracer->lastStoredId;
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
     * @return array{trace_id: string|null, status: int|null, error: string|null, mode?: string, undo?: array<string, mixed>|null}
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
            // The child traces the whole request from its first line, which takes several times the memory of a request that is listened to,
            // so it has a limit of its own. A fatal error is written to stderr (a command line php.ini often hides it), so it can be reported.
            $limit = trim((string) App::config()->get('request-tracer.replay.memory_limit', '2G'));
            $options = ['-d', 'display_errors=stderr', '-d', 'log_errors=0'];
            if ($limit !== '') {
                array_push($options, '-d', 'memory_limit='.$limit);
            }
            $process = new Process(
                [$php, ...$options, App::application()->basePath('artisan'), 'request-tracer:replay', '--payload-file='.$file, '--json', '--no-interaction'],
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
                            'mode' => is_string($decoded['mode'] ?? null) ? $decoded['mode'] : $replay->mode,
                            'undo' => is_array($decoded['undo'] ?? null) ? $decoded['undo'] : null,
                        ];
                    }
                }
            }

            $output = trim($process->getErrorOutput()."\n".$process->getOutput());

            return ['trace_id' => null, 'status' => null, 'error' => $process->isSuccessful() ? 'The replay produced no result.' : self::childFailure($process->getExitCode(), $output, $limit)];
        } finally {
            @unlink($file);
        }
    }

    /** What to tell about a replay process that died: PHP's own message when there is one, otherwise the exit code and the usual cause. */
    private static function childFailure(?int $exitCode, string $output, string $limit): string
    {
        $message = 'The replay process failed'.($exitCode !== null ? ' (exit code '.$exitCode.')' : '').': ';
        $output = mb_substr($output, -600);
        $now = $limit === '' ? "the PHP command line's" : $limit;
        if (stripos($output, 'Allowed memory size') !== false) {
            return $message.$output.' The request needs more memory than the replay may use: raise `request-tracer.replay.memory_limit` (now '.$now.').';
        }
        if ($output === '') {
            return $message.'it stopped without a message, which is usually PHP running out of memory or time. Raise `request-tracer.replay.memory_limit` (now '.$now.') or `request-tracer.replay.timeout`.';
        }

        return $message.$output;
    }

    /**
     * Simulation: mail goes nowhere, notifications are dropped, queued jobs are discarded, broadcasts are not sent, calls made with
     * Laravel's HTTP client are answered locally, and the cache and the session stay in memory.
     */
    private function makeSideEffectsHarmless(): void
    {
        $config = App::config();
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('mail.default', 'array');
        $config->set('queue.connections.request-tracer-discard', ['driver' => 'null']);
        $config->set('queue.default', 'request-tracer-discard');
        $config->set('broadcasting.connections.null', ['driver' => 'null']);
        $config->set('broadcasting.default', 'null');

        $config->set('cache.stores.request-tracer-memory', ['driver' => 'array', 'serialize' => false]);
        $config->set('cache.default', 'request-tracer-memory');
        $config->set('session.driver', 'array');
        try {
            Cache::forgetDriver();
            $session = App::container()->make('session');
            if (method_exists($session, 'forgetDrivers')) {
                $session->forgetDrivers();
            }
        } catch (\Throwable) {
            // a store that was already built keeps what it was: the rest of the simulation still holds
        }

        try {
            Http::fake(['*' => Http::response(['simulated' => true], 200)]);
            Notification::fake();
        } catch (\Throwable) {
            // an application without the HTTP client or notifications has nothing to fake
        }
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
