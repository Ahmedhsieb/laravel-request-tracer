<?php

declare(strict_types=1);

namespace RequestTracer\Http\Controllers;

use Illuminate\Routing\Controller;
use RequestTracer\Support\Brand;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the built dashboard (`dist/`). Assets published with `vendor:publish --tag=request-tracer-assets`
 * (public/vendor/request-tracer) take precedence over the copy inside the package, so an application can pin or patch them.
 */
final class AssetController extends Controller
{
    private const TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'ico' => 'image/x-icon',
        'woff2' => 'font/woff2',
        'woff' => 'font/woff',
        'map' => 'application/json',
        'json' => 'application/json',
    ];

    public function __invoke(string $path): Response
    {
        $file = self::locate($path);
        abort_if($file === null, 404);

        $type = self::TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
        $response = new BinaryFileResponse($file, 200, ['Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff']);
        $response->setPublic();
        $response->setMaxAge(31_536_000);
        $response->setEtag(substr((string) md5_file($file), 0, 16));

        return $response;
    }

    /** Absolute path of an asset, or null when it does not exist or the name tries to leave the asset directories. */
    public static function locate(string $path): ?string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || preg_match('~^[A-Za-z0-9._\-/]+$~', $path) !== 1) {
            return null;
        }

        foreach ([public_path('vendor/'.Brand::NAME), dirname(__DIR__, 3).'/dist'] as $dir) {
            $candidate = $dir.'/'.$path;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
