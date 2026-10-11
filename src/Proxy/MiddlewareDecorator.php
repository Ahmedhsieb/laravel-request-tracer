<?php

declare(strict_types=1);

namespace RequestTracer\Proxy;

use Closure;
use RequestTracer\Hot;

/**
 * Wraps a middleware instance the container builds with a custom closure (Laravel's own `StartSession` is one), where a
 * generated subclass cannot be used because the closure decides how the instance is constructed.
 *
 * It forwards `handle()` inside a `middleware` step and everything else untouched. Middleware that has a `terminate()` method
 * is wrapped in {@see TerminableMiddlewareDecorator}, because the HTTP kernel looks for that method with `method_exists()`.
 */
class MiddlewareDecorator
{
    public function __construct(
        protected readonly object $inner,
        private readonly string $label,
        private readonly ?string $file,
        private readonly ?int $line,
    ) {}

    /** @param  mixed  ...$parameters  middleware parameters (`throttle:60,1`) */
    public function handle(mixed $request, Closure $next, mixed ...$parameters): mixed
    {
        if (! Hot::$on) {
            return $this->forward('handle', $request, $next, ...$parameters);
        }

        $span = Runtime::enter($this->label, $this->file, $this->line, Runtime::MIDDLEWARE, []);
        try {
            $response = $this->forward('handle', $request, $next, ...$parameters);
        } catch (\Throwable $e) {
            Runtime::fail($span, $e);

            throw $e;
        }
        Runtime::leave($span, $response);

        return $response;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->forward($method, ...$arguments);
    }

    /** The decorated object's type is only known at run time, so its methods are called by name. */
    protected function forward(string $method, mixed ...$arguments): mixed
    {
        return $this->inner->{$method}(...$arguments);
    }
}
