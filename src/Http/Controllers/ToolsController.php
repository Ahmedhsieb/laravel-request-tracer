<?php

declare(strict_types=1);

namespace RequestTracer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Recording\TokenService;
use RequestTracer\Recording\WatchList;
use RequestTracer\Support\Paths;
use RequestTracer\Tracer;

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
