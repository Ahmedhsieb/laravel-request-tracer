<?php

declare(strict_types=1);

namespace RequestTracer\Collectors;

use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Str;
use RequestTracer\Http\Middleware\TraceAction;
use RequestTracer\Proxy\ProxyRegistry;
use RequestTracer\Recording\WatchList;
use RequestTracer\RecordingState;
use RequestTracer\Support\App;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Route and authentication facts about the request, plus the `controller` span (via the TraceAction middleware that is
 * appended to the matched route). Also where a tentative recording is confirmed or dropped: once the user is known.
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

                $name = (string) $route->getName();
                $excluded = (array) $tracer->config('exclude.routes', []);
                if ($name !== '' && $excluded !== [] && Str::is($excluded, $name)) {
                    $session->discard = true;                      // an excluded route: record nothing, store nothing
                }

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
                $session = $tracer->session();
                $id = $event->user->getAuthIdentifier();
                if ($session === null || ! (is_int($id) || is_string($id))) {
                    return;
                }
                $session->meta['user_id'] = $id;

                if ($session->state === RecordingState::Tentative) {
                    $this->confirmTentative($tracer, (string) $id);
                }
            }, 'authenticated');
        });
    }

    /** A tentative recording is kept when the user matches its token, the watch list, or it was sampled (and may now be kept). */
    private function confirmTentative(Tracer $tracer, string $userId): void
    {
        $hints = (array) ($tracer->session()?->bag['tentative'] ?? []);

        if (isset($hints['token_uid'])) {
            if ((string) $hints['token_uid'] === $userId) {
                $tracer->confirm('token');
            }

            return;                                         // a token bound to another user never turns into a watch/sample recording
        }
        if (! empty($hints['watch']) && App::container()->make(WatchList::class)->contains($userId)) {
            $tracer->confirm('watch');

            return;
        }
        if (! empty($hints['sample'])) {
            $tracer->confirm('sample');
        }
    }
}
