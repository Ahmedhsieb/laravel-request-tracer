<?php

declare(strict_types=1);

namespace RequestTracer\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RequestTracer\DeepTrace\Loader;
use RequestTracer\Recording\RecordingDecider;
use RequestTracer\Support\Brand;
use RequestTracer\Tracer;

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
            'poll_seconds' => (int) $tracer->config('dashboard.poll_seconds', 5),
            'mask' => (string) $tracer->config('masking.mask', Brand::MASK),
            'deep_trace' => ['enabled' => (bool) $tracer->config('deep_trace.enabled', true)],
            'limits' => [
                'max_spans' => (int) $tracer->config('limits.max_spans', 5000),
                'max_hits' => (int) $tracer->config('deep_trace.max_hits', 20000),
            ],
        ];
    }
}
