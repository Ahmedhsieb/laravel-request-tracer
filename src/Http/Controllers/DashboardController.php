<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Controllers;

use Ahmedhsieb\RequestTracer\Recording\RecordingDecider;
use Ahmedhsieb\RequestTracer\Support\Brand;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** The single page the Vue app lives in. Every non-API, non-asset URL below the dashboard path renders it (client-side routing). */
final class DashboardController extends Controller
{
    public function __invoke(Request $request, Tracer $tracer, RecordingDecider $decider): View
    {
        $path = '/'.trim((string) $tracer->config('dashboard.path', Brand::NAME), '/');

        return view(Brand::VIEW, [
            'bootstrap' => [
                'basePath' => $path,
                'apiUrl' => $path.'/api/v1',
                'csrf' => csrf_token(),
                'meta' => MetaController::payload($request, $tracer, $decider),
            ],
            'assetBase' => $path.'/assets',
            'version' => $this->assetVersion('app.js').$this->assetVersion('app.css'),
            'title' => Brand::TITLE,
        ]);
    }

    private function assetVersion(string $file): string
    {
        $path = AssetController::locate($file);

        return $path === null ? '0' : substr((string) md5_file($path), 0, 8);
    }
}
