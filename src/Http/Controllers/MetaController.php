<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Controllers;

use Ahmedhsieb\RequestTracer\DeepTrace\Loader;
use Ahmedhsieb\RequestTracer\Recording\Listening;
use Ahmedhsieb\RequestTracer\Recording\RecordingDecider;
use Ahmedhsieb\RequestTracer\Replay\Replayer;
use Ahmedhsieb\RequestTracer\Support\Brand;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** `GET /api/v1/meta`: what the dashboard header needs (environment badge, recording mode, user, thresholds). */
final class MetaController extends Controller
{
    public function __invoke(Request $request, Tracer $tracer, RecordingDecider $decider): JsonResponse
    {
        return response()->json(self::payload($request, $tracer, $decider));
    }

    /** @return array<string, mixed> */
    public static function payload(Request $request, Tracer $tracer, RecordingDecider $decider): array
    {
        $user = $request->user();
        $environment = (string) app()->environment();
        $mode = $decider->mode();

        return [
            'name' => Brand::TITLE,
            'version' => Loader::PACKAGE_VERSION,
            'environment' => $environment,
            'is_production' => ! $decider->isLocal(),
            'mode' => $mode,
            'enabled' => $tracer->enabled(),
            'listening' => Listening::active(),
            'listen_command' => 'php artisan '.Brand::NAME.':listen',
            'replay' => ['enabled' => Replayer::allowed(), 'real' => Replayer::realAllowed(), 'undo_hours' => (float) $tracer->config('replay.undo_hours', 24)],
            'journal' => [
                'enabled' => (bool) $tracer->config('journal.enabled', false),
                'hours' => (float) $tracer->config('journal.hours', 1),
                'max_rows' => (int) $tracer->config('journal.max_rows', 500),
            ],
            'code' => [
                'paths' => array_values(array_filter((array) $tracer->config('code.paths', ['app']), 'is_string')),
                'except' => array_values(array_filter((array) $tracer->config('code.except', []), 'is_string')),
                'line_by_line' => (bool) $tracer->config('code.line_by_line', true) && (bool) $tracer->config('deep_trace.enabled', true),
            ],
            'recording' => [
                'mode' => $mode,
                'header' => (string) $tracer->config('triggers.header', Brand::HEADER),
                'watch_list' => (bool) $tracer->config('triggers.watch_list.enabled', true),
                'sampling' => [
                    'enabled' => (bool) $tracer->config('triggers.sampling.enabled', false),
                    'rate' => (float) $tracer->config('triggers.sampling.rate', 0.01),
                ],
                'token_ttl' => (int) $tracer->config('triggers.token_ttl', 300),
            ],
            'user' => $user === null ? null : [
                'id' => $user->getAuthIdentifier(),
                'name' => (string) ($user->name ?? $user->email ?? $user->getAuthIdentifier()),
            ],
            'slow_ms' => (float) $tracer->config('dashboard.slow_ms', 500),
            'slow_step_ms' => (float) $tracer->config('dashboard.slow_step_ms', 100),
            'poll_seconds' => (int) $tracer->config('dashboard.poll_seconds', 5),
            'mask' => (string) $tracer->config('masking.mask', Brand::MASK),
            'deep_trace' => ['enabled' => (bool) $tracer->config('deep_trace.enabled', true)],
            'unlimited' => $tracer->unlimited(),
            'limits' => [
                'max_spans' => (int) $tracer->config('limits.max_spans', 5000),
                'max_hits' => (int) $tracer->config('deep_trace.max_hits', 20000),
            ],
        ];
    }
}
