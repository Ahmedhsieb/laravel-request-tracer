<?php

declare(strict_types=1);

namespace RequestTracer\Http\Middleware;

use Closure;
use Illuminate\Contracts\View\View as ViewContract;
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
                            $view = $this->viewOf($response);
                            if ($view !== null) {
                                // a controller that returns a view: what it handed to the view is the interesting part, not the HTML
                                // (the data is the root of the output, so the dumper's depth limit counts from the variables themselves)
                                $span->output = $session->dump($this->viewData($view));
                                $span->meta['view'] = $view->name();
                                $span->meta['summary'] = 'Returns view '.$view->name();
                            } else {
                                $span->output = $session->dump($response);
                                $span->meta['summary'] = 'Returns '.Summary::of($response);
                            }
                        }
                        $session->recorder->end($span);
                    }
                }, 'controller span end');
            }
        }
    }

    /** The view the action returned (`return view('x', compact('a'))`), when that is what the response was made from. */
    private function viewOf(Response $response): ?ViewContract
    {
        $original = method_exists($response, 'getOriginalContent') ? $response->getOriginalContent() : null;

        return $original instanceof ViewContract ? $original : null;
    }

    /** @return array<string, mixed> what the action passed to the view (the framework's own `__env`, `app` and `errors` are not part of it) */
    private function viewData(ViewContract $view): array
    {
        $data = [];
        foreach ($view->getData() as $key => $value) {
            if (is_string($key) && ! str_starts_with($key, '__') && $key !== 'obLevel') {
                $data[$key] = $value;
            }
        }

        return $data;
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
