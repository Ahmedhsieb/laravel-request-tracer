<?php

declare(strict_types=1);

namespace RequestTracer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use RequestTracer\SpanType;
use RequestTracer\Support\Failure;
use RequestTracer\Support\Locator;
use RequestTracer\Support\Summary;
use RequestTracer\Tracer;
use Symfony\Component\HttpFoundation\Response;

/**
 * Appended to the end of the matched route's middleware list (by the RequestCollector), so it wraps exactly the controller
 * action. Produces the `controller` span with the route parameters as input and the response as output.
 */
final class TraceAction
{
    public function __construct(private readonly Tracer $tracer) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if (! $this->tracer->on) {
            return $next($request);
        }

        $span = Failure::guard(function () use ($request) {
            $session = $this->tracer->session();
            $route = $request->route();
            if ($session === null || ! $route instanceof Route) {
                return null;
            }
            [$label, $file, $line] = $this->describe($route);
            $span = $session->recorder->begin(SpanType::Controller, $label, $file, $line);
            if (! $span->ghost) {
                $span->input = $session->dump(['route' => $route->uri(), 'parameters' => $route->parameters()]);
            }

            return $span;
        }, 'controller span start');

        $response = null;
        try {
            return $response = $next($request);
        } finally {
            if ($span !== null) {
                Failure::guard(function () use ($span, $response) {
                    $session = $this->tracer->session();
                    if ($session !== null) {
                        if (! $span->ghost && $response instanceof Response) {
                            $span->output = $session->dump($response);
                            $span->meta['summary'] = 'Returns '.Summary::of($response);
                        }
                        $session->recorder->end($span);
                    }
                }, 'controller span end');
            }
        }
    }

    /** @return array{0: string, 1: string|null, 2: int|null} */
    private function describe(Route $route): array
    {
        $uses = $route->getAction('uses');
        $name = $route->getActionName();

        if ($uses instanceof Closure) {
            [$file, $line] = Locator::callable($uses);

            return ['Closure'.($file !== null ? " ({$file}:{$line})" : ''), $file, $line];
        }

        if (is_string($name) && str_contains($name, '@')) {
            [$class, $method] = explode('@', $name, 2);
            [$file, $line] = Locator::method($class, $method);

            return [Locator::shortClass($class).'@'.$method, $file, $line];
        }

        if (is_string($name) && $name !== '') {
            [$file, $line] = Locator::method($name, '__invoke');

            return [Locator::shortClass($name), $file, $line];
        }

        return [$route->uri(), null, null];
    }
}
