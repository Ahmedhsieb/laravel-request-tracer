<?php

declare(strict_types=1);

namespace RequestTracer\Journal;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use RequestTracer\Dumper\Masker;
use RequestTracer\Recording\RecordingDecider;
use RequestTracer\RecordingState;
use RequestTracer\Replay\Replayer;
use RequestTracer\Replay\ReplayRequest;
use RequestTracer\Support\App;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;
use RequestTracer\TraceSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request journal: one light row per request (when `journal.enabled` is on), so a request that nobody traced can still be found
 * ("what hit us at 14:03?") and run again with a full trace through Replay.
 *
 * What is kept is what a replay needs: method, URL, the allow-listed headers, the body, the user id, and how it ended (status, the
 * exception, the time). Secrets are masked before anything is stored, exactly as in a trace: by key name, and by value pattern. That
 * has one consequence worth knowing: a masked value cannot be sent again, so a replay of a request with a password in it carries the
 * mask, and the dashboard says so.
 *
 * It costs one INSERT per request, after the response was sent. It never touches the request itself.
 */
class Journal
{
    private const ATTRIBUTE = 'request_tracer.journal';

    /** Roughly one write in this many also trims old rows, so the table stays small without a scheduler. */
    private const TRIM_ONE_IN = 100;

    public function __construct(private readonly Tracer $tracer, private readonly JournalStorage $storage, private readonly RecordingDecider $decider) {}

    public function enabled(): bool
    {
        return (bool) App::config()->get('request-tracer.journal.enabled', false);
    }

    /** At the start of the request: remember what it looked like (the body may be read from the stream only here and later in the pipeline). */
    public function capture(Request $request): void
    {
        Failure::guard(function () use ($request): void {
            if (! $this->enabled() || Replayer::active() || $this->decider->excluded($request)) {
                return;
            }
            [$body, $cut, $masked] = $this->body($request);
            $request->attributes->set(self::ATTRIBUTE, [
                'started' => defined('LARAVEL_START') && PHP_SAPI !== 'cli' ? (float) LARAVEL_START : microtime(true),
                'method' => $request->getMethod(),
                'url' => $request->getRequestUri(),
                'headers' => $this->headers($request),
                'body' => $body,
                'body_cut' => $cut,
                'masked' => $masked,
            ]);
        }, 'journal capture');
    }

    /** After the response was sent: complete the row and store it. */
    public function record(Request $request, Response $response, ?TraceSession $session): void
    {
        Failure::guard(function () use ($request, $response, $session): void {
            $captured = $request->attributes->get(self::ATTRIBUTE);
            if (! is_array($captured)) {
                return;
            }
            $route = $request->route();
            $routeName = is_object($route) && method_exists($route, 'getName') ? $route->getName() : null;
            if (is_string($routeName) && $routeName !== '' && Str::is((array) App::config()->get('request-tracer.exclude.routes', []), $routeName)) {
                return;
            }

            $this->storage->store([
                'method' => $captured['method'],
                'url' => $captured['url'],
                'route_name' => $routeName,
                'status' => $response->getStatusCode(),
                'duration_ms' => (microtime(true) - (float) $captured['started']) * 1000,
                'user_id' => $this->userId(),
                'failure' => $this->failure($response),
                'headers' => $captured['headers'],
                'body' => $captured['body'],
                'body_cut' => $captured['body_cut'],
                'masked' => $captured['masked'],
                'trace_id' => $session !== null && $session->state === RecordingState::On ? $session->id : null,
            ]);

            if (random_int(1, self::TRIM_ONE_IN) === 1) {
                $this->trim();
            }
        }, 'journal record');
    }

    /** Drop what is older than `journal.hours` and beyond `journal.max_rows`. @return int rows deleted */
    public function trim(): int
    {
        $config = App::config();

        return $this->storage->trim((float) $config->get('request-tracer.journal.hours', 1), (int) $config->get('request-tracer.journal.max_rows', 500));
    }

    /**
     * What the replay needs, from a stored entry.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function toReplayRequest(array $entry): ReplayRequest
    {
        $headers = [];
        foreach ((array) ($entry['headers'] ?? []) as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $headers[$name] = $value;
            }
        }
        $user = $entry['user_id'] ?? null;

        return new ReplayRequest(
            (string) $entry['method'],
            (string) $entry['url'],
            $headers,
            is_string($entry['body'] ?? null) && $entry['body'] !== '' ? $entry['body'] : null,
            is_string($user) || is_int($user) ? $user : null,
        );
    }

    /** @return array<string, string> */
    private function headers(Request $request): array
    {
        $masker = $this->tracer->masker();
        $allowed = array_map('strtolower', array_filter((array) App::config()->get('request-tracer.capture.request_headers', []), 'is_string'));
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);
            $value = is_array($values) ? ($values[0] ?? null) : $values;
            if (! in_array($name, $allowed, true) || ! is_string($value) || $masker->isSensitiveKey($name)) {
                continue;
            }
            $headers[$name] = mb_substr($masker->maskString($value), 0, 1000);
        }

        return $headers;
    }

    /**
     * The body, masked, in the form a replay can send again.
     *
     * @return array{0: string|null, 1: bool, 2: bool} body, "too large to keep", "something was masked"
     */
    private function body(Request $request): array
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return [null, false, false];
        }

        try {
            $json = $request->isJson();
            $data = $json ? $request->json()->all() : $request->request->all();
        } catch (\Throwable) {
            return [null, false, false];
        }
        if ($data === []) {
            return [null, false, false];
        }

        $masker = $this->tracer->masker();
        $masked = false;
        $clean = $this->mask($data, $masker, $masked);
        $encoded = $json ? (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : http_build_query($clean);
        if (strlen($encoded) > max(1024, (int) App::config()->get('request-tracer.journal.body_max_bytes', 16384))) {
            return [null, true, $masked];
        }

        return [$encoded, false, $masked];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function mask(array $values, Masker $masker, bool &$masked): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if ($masker->isSensitiveKey($key)) {
                $out[$key] = $masker->mask;
                $masked = true;
            } elseif (is_array($value)) {
                $out[$key] = $this->mask($value, $masker, $masked);
            } elseif (is_string($value) && $masker->hasValuePatterns()) {
                $scrubbed = $masker->maskString($value);
                $masked = $masked || $scrubbed !== $value;
                $out[$key] = $scrubbed;
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function failure(Response $response): ?string
    {
        $exception = property_exists($response, 'exception') ? $response->exception : null;
        if ($exception instanceof \Throwable) {
            return mb_substr($exception::class.': '.$exception->getMessage(), 0, 500);
        }

        return $response->getStatusCode() >= 500 ? 'HTTP '.$response->getStatusCode() : null;
    }

    private function userId(): int|string|null
    {
        return Failure::guard(function () {
            $manager = Auth::getFacadeRoot();
            if (is_object($manager) && method_exists($manager, 'hasResolvedGuards') && ! $manager->hasResolvedGuards()) {
                return null;
            }
            $id = Auth::id();

            return is_int($id) || is_string($id) ? $id : null;
        }, 'journal user id');
    }
}
