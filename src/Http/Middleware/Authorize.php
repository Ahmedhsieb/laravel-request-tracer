<?php

declare(strict_types=1);

namespace RequestTracer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RequestTracer\Support\Brand;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protects the dashboard and the JSON API with the `viewRequestTracer` gate (like Horizon).
 *
 * Outside the local environment access is denied unless the application defines the gate, for example in a service provider:
 *
 *     Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['dev@example.com']));
 */
final class Authorize
{
    public function handle(Request $request, Closure $next): Response
    {
        $gate = (string) config(Brand::NAME.'.dashboard.gate', Brand::GATE);

        abort_unless(Gate::has($gate) && Gate::forUser($request->user())->allows($gate), 403);

        return $next($request);
    }
}
