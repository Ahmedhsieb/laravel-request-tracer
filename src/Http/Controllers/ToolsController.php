<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Controllers;

use Ahmedhsieb\RequestTracer\DeepTrace\Loader;
use Ahmedhsieb\RequestTracer\Recording\TokenService;
use Ahmedhsieb\RequestTracer\Recording\WatchList;
use Ahmedhsieb\RequestTracer\Replay\CurlParser;
use Ahmedhsieb\RequestTracer\Replay\Replayer;
use Ahmedhsieb\RequestTracer\Replay\ReplayRequest;
use Ahmedhsieb\RequestTracer\Replay\UndoLog;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Paths;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Things the dashboard can *do*: issue a signed recording token, manage the watch list, queue a file for Deep trace. */
final class ToolsController extends Controller
{
    public function __construct(private readonly Tracer $tracer) {}

    public function issueToken(Request $request, TokenService $tokens): JsonResponse
    {
        $data = $request->validate([
            'ttl' => ['nullable', 'integer', 'min:10', 'max:'.TokenService::MAX_TTL],
            'user_id' => ['nullable', 'string', 'max:64'],
            'route' => ['nullable', 'string', 'max:255'],
        ]);

        $issued = $tokens->issue(
            isset($data['ttl']) ? (int) $data['ttl'] : null,
            $data['user_id'] ?? null,
            $data['route'] ?? null,
        );
        $header = (string) $this->tracer->config('triggers.header', 'X-Request-Tracer');

        return response()->json(['data' => $issued + [
            'header' => $header,
            'example' => sprintf("curl -H '%s: %s' %s", $header, $issued['token'], rtrim((string) config('app.url'), '/').'/your/endpoint'),
        ]], 201);
    }

    /** Run a request again, traced: either a pasted cURL command or method, URL, headers and body. */
    public function replay(Request $request): JsonResponse
    {
        abort_unless(Replayer::allowed(), 403, 'Replay is switched off in this environment (request-tracer.replay.enabled).');

        $data = $request->validate([
            'curl' => ['nullable', 'string', 'max:50000'],
            'method' => ['nullable', 'string', 'max:10'],
            'url' => ['nullable', 'string', 'max:2048'],
            'headers' => ['nullable', 'array'],
            'body' => ['nullable', 'string', 'max:500000'],
            'as_user' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'in:simulate,real'],
            'confirm_real' => ['nullable', 'boolean'],
        ]);
        $real = ($data['mode'] ?? ReplayRequest::SIMULATE) === ReplayRequest::REAL;
        if ($real) {
            abort_unless(Replayer::realAllowed(), 403, 'Running a replay for real is switched off in this environment (request-tracer.replay.real).');
            abort_unless(($data['confirm_real'] ?? false) === true, 422, 'Running for real needs an explicit confirmation (confirm_real).');
        }

        try {
            if (isset($data['curl']) && trim($data['curl']) !== '') {
                $replay = CurlParser::parse($data['curl']);
                $replay = isset($data['as_user']) && $data['as_user'] !== '' ? $replay->withUser($data['as_user']) : $replay;
            } elseif (isset($data['url']) && $data['url'] !== '') {
                $replay = ReplayRequest::fromArray($data);
            } else {
                abort(422, 'Paste a cURL command, or give a method and a URL.');
            }
        } catch (\InvalidArgumentException $e) {
            abort(422, $e->getMessage());
        }

        $replay = $replay->withMode($real ? ReplayRequest::REAL : ReplayRequest::SIMULATE);

        set_time_limit((int) $this->tracer->config('replay.timeout', 120) + 15);
        $result = App::container()->make(Replayer::class)->runInChild($replay);

        return response()->json(['data' => $result], $result['error'] === null ? 200 : 422);
    }

    /** What can still be undone for the trace of a replay that ran for real (no values, only counts). */
    public function undoStatus(string $trace): JsonResponse
    {
        abort_unless(Replayer::allowed(), 403, 'Replay is switched off in this environment (request-tracer.replay.enabled).');

        return response()->json(['data' => UndoLog::summary($trace)]);
    }

    /** Take back what that replay changed. Nothing is changed when a row differs from what the replay left, unless `force`. */
    public function undo(Request $request, string $trace): JsonResponse
    {
        abort_unless(Replayer::allowed(), 403, 'Replay is switched off in this environment (request-tracer.replay.enabled).');
        $data = $request->validate(['force' => ['nullable', 'boolean']]);

        $result = UndoLog::apply($trace, (bool) ($data['force'] ?? false));

        return response()->json(['data' => $result], $result['error'] === null ? 200 : 422);
    }

    public function watchList(WatchList $watch): JsonResponse
    {
        return response()->json(['data' => $this->watchRows($watch)]);
    }

    public function watch(Request $request, WatchList $watch): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'string', 'max:64'],
            'minutes' => ['nullable', 'integer', 'min:1', 'max:'.max(1, intdiv((int) $this->tracer->config('triggers.watch_list.max_ttl', 3600), 60))],
        ]);
        $watch->add($data['user_id'], (int) ($data['minutes'] ?? 30));

        return response()->json(['data' => $this->watchRows($watch)], 201);
    }

    public function unwatch(string $user, WatchList $watch): JsonResponse
    {
        $watch->remove($user);

        return response()->json(['data' => $this->watchRows($watch)]);
    }

    public function queue(): JsonResponse
    {
        return response()->json(['data' => $this->queueRows()]);
    }

    public function queueFile(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:1024']]);
        $absolute = $this->resolveFile($data['file']);
        abort_if($absolute === null, 422, 'That file cannot be traced: it must be a PHP file inside the application (not in vendor/).');

        Loader::queue()->add($absolute, (int) $this->tracer->config('deep_trace.queue_ttl', 3600));

        return response()->json(['data' => $this->queueRows()], 201);
    }

    public function dequeueFile(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:1024']]);
        $absolute = $this->resolveFile($data['file']);
        if ($absolute !== null) {
            Loader::queue()->remove($absolute);
        }

        return response()->json(['data' => $this->queueRows()]);
    }

    /** A path the dashboard shows (relative to the app) -> an absolute path, only for PHP files inside the application. */
    private function resolveFile(string $file): ?string
    {
        $absolute = Paths::absolute(str_replace('\\', '/', $file));
        $real = realpath($absolute);
        $base = realpath(base_path());
        if ($real === false || $base === false || ! is_file($real) || strtolower(pathinfo($real, PATHINFO_EXTENSION)) !== 'php') {
            return null;
        }
        $real = Paths::normalize($real);
        $base = Paths::normalize($base);
        if (! str_starts_with($real, $base.'/') || str_contains($real, '/vendor/') || str_contains($real, '/storage/')) {
            return null;
        }

        return $real;
    }

    /** @return list<array{user_id: string, expires_at: int}> */
    private function watchRows(WatchList $watch): array
    {
        $rows = [];
        foreach ($watch->entries() as $user => $expires) {
            $rows[] = ['user_id' => (string) $user, 'expires_at' => $expires];
        }

        return $rows;
    }

    /** @return list<array{file: string, expires_at: int}> */
    private function queueRows(): array
    {
        $rows = [];
        foreach (Loader::queue()->read() as $file => $expires) {
            $rows[] = ['file' => Paths::relative($file) ?? $file, 'expires_at' => $expires];
        }

        return $rows;
    }
}
