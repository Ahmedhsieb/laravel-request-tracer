<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use RequestTracer\Http\Middleware\TraceAction;
use RequestTracer\Proxy\ProxyRegistry;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Route and authentication facts about the request, plus the `controller` span (via the TraceAction middleware that is
 * appended to the matched route).
 */
final class RequestCollector implements Collector
{
    public static function key(): string
    {
        return 'request';
    }

    public function register(Tracer $tracer, Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(RouteMatched::class, function (RouteMatched $event) use ($tracer, $app): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(function () use ($tracer, $event, $app): void {
                $session = $tracer->session();
                if ($session === null) {
                    return;
                }
                $route = $event->route;
                $session->meta['route_name'] = $route->getName();
                $session->meta['route_uri'] = $route->uri();

                // Every middleware the pipeline is about to resolve becomes a `middleware` span (via the proxy hook).
                $registry = $app->make(ProxyRegistry::class);
                $registry->markMiddleware($app->make('router')->gatherRouteMiddleware($route));

                // Put our middleware last, after route and controller middleware, without touching the route definition.
                $middleware = $route->gatherMiddleware();
                if (! in_array(TraceAction::class, $middleware, true)) {
                    $route->computedMiddleware = array_merge($middleware, [TraceAction::class]);
                }
            }, 'route matched');
        });

        $events->listen(Authenticated::class, function (Authenticated $event) use ($tracer): void {
            if (! $tracer->on) {
                return;
            }
            Failure::guard(function () use ($tracer, $event): void {
                $id = $event->user->getAuthIdentifier();
                if ($tracer->session() !== null && (is_int($id) || is_string($id))) {
                    $tracer->session()->meta['user_id'] = $id;
                }
            }, 'authenticated');
        });
    }
}
