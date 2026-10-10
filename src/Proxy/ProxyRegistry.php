<?php

declare(strict_types=1);

namespace RequestTracer\Proxy;

use Closure;
use Illuminate\Container\Container;
use RequestTracer\Attributes\NoTrace;
use RequestTracer\Attributes\Trace;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;

/**
 * Decides which classes resolved from the container get a traced subclass, and swaps the container binding for one that
 * builds the subclass (through the container, so the parent constructor receives its dependencies).
 *
 * The decision per class is memoised for the life of the process; the hook does nothing unless a trace is being recorded
 * (singletons built at boot, before any request, are never proxied).
 *
 * What cannot be proxied: `new Foo()`, final classes, bindings to custom closures, private/protected/static/final methods.
 */
final class ProxyRegistry
{
    /** @var array<string, true> classes already examined */
    private array $handled = [];

    /** @var array<string, true> */
    private array $middleware = [];

    /** @var array<string, string> pattern => compiled regex */
    private array $compiled = [];

    public function __construct(private readonly ProxyGenerator $generator) {}

    /**
     * Remember that these classes are middleware, so their `handle()` becomes a `middleware` span when the pipeline resolves them.
     *
     * @param  iterable<mixed>  $names  class names, optionally with ":parameters"; closures are ignored
     */
    public function markMiddleware(iterable $names): void
    {
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $class = ltrim(explode(':', $name, 2)[0], '\\');
            if (str_contains($class, '\\')) {
                $this->middleware[$class] = true;
            }
        }
    }

    /** The container's beforeResolving hook. */
    public function handle(string $abstract, Container $container, Tracer $tracer): void
    {
        if (isset($this->handled[$abstract])) {
            return;
        }
        $this->handled[$abstract] = true;
        if (! str_contains($abstract, '\\')) {
            return;
        }

        try {
            $options = $this->plan($abstract, $tracer);
            if ($options === null) {
                return;
            }
            if ($container->isShared($abstract) && $container->resolved($abstract)) {
                return;                                   // already built (as the original class)
            }
            if ($container->bound($abstract) && ! $this->bindsItself($container, $abstract)) {
                return;                                   // interface binding or a custom closure: the concrete class goes through here itself
            }

            if (! class_exists($abstract)) {
                return;
            }
            $result = $this->generator->proxyFor($abstract, $options);
            foreach ($result->notes as $note) {
                $tracer->note('not_traceable', $abstract, $note);
            }
            if ($result->proxyClass === null) {
                return;
            }

            $proxy = $result->proxyClass;
            $this->copyContextualBindings($container, $abstract, $proxy);
            $container->bind($abstract, static fn (Container $c) => $c->build($proxy), $container->isShared($abstract));
        } catch (\Throwable $e) {
            Failure::report($e, 'proxy registry ('.$abstract.')');
        }
    }

    /**
     * @return array{only?: list<string>|null, middleware?: bool, vendor_methods?: bool}|null null = leave the class alone
     */
    private function plan(string $class, Tracer $tracer): ?array
    {
        // never trace the tracer (its own middleware, collectors, storage...); the test fixtures live under RequestTracer\Tests
        if (str_starts_with($class, 'RequestTracer\\') && ! str_starts_with($class, 'RequestTracer\\Tests\\')) {
            return null;
        }

        $exclude = (array) $tracer->config('proxies.exclude', []);
        if ($exclude !== [] && $this->matches($class, $exclude)) {
            return null;
        }

        if (isset($this->middleware[$class]) && $tracer->config('proxies.middleware', true)) {
            return ['middleware' => true];
        }

        $vendorMethods = (bool) $tracer->config('proxies.vendor_methods', false);
        $namespaces = (array) $tracer->config('proxies.namespaces', []);
        if ($namespaces !== [] && $this->matches($class, $namespaces) && class_exists($class)) {
            return ['vendor_methods' => $vendorMethods];
        }

        // #[Trace] on a class or method outside the configured namespaces
        foreach ((array) $tracer->config('proxies.attribute_namespaces', []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($class, $prefix) && class_exists($class)) {
                return $this->planFromAttributes($class, $vendorMethods);
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     * @return array{only?: list<string>|null, vendor_methods?: bool}|null
     */
    private function planFromAttributes(string $class, bool $vendorMethods): ?array
    {
        $ref = new \ReflectionClass($class);
        if ($ref->isFinal() || $ref->isAbstract() || $ref->getAttributes(NoTrace::class) !== []) {
            return null;
        }
        if ($ref->getAttributes(Trace::class) !== []) {
            return ['vendor_methods' => $vendorMethods];
        }
        $only = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Trace::class) !== []) {
                $only[] = $method->getName();
            }
        }

        return $only === [] ? null : ['only' => $only, 'vendor_methods' => $vendorMethods];
    }

    /** @param  array<int, mixed>  $patterns */
    private function matches(string $class, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            $regex = $this->compiled[$pattern] ??= '~^'.str_replace('\\*', '[^\\\\]*', preg_quote(ltrim($pattern, '\\'), '~')).'(?:\\\\.*)?$~i';
            if (preg_match($regex, $class) === 1) {
                return true;
            }
        }

        return false;
    }

    /** True when the container binding is the default "build this very class" wrapper that `singleton(Foo::class)` creates. */
    private function bindsItself(Container $container, string $abstract): bool
    {
        $binding = $container->getBindings()[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? null;
        if (! $concrete instanceof Closure) {
            return false;
        }
        try {
            $vars = (new \ReflectionFunction($concrete))->getStaticVariables();

            return ($vars['abstract'] ?? null) === $abstract && ($vars['concrete'] ?? null) === $abstract;
        } catch (\Throwable) {
            return false;
        }
    }

    /** `when(Foo::class)->needs(...)->give(...)` is keyed by the class being built; the proxy is a different class name. */
    private function copyContextualBindings(Container $container, string $original, string $proxy): void
    {
        (function () use ($original, $proxy): void {
            if (isset($this->contextual[$original])) {
                $this->contextual[$proxy] = $this->contextual[$original];
            }
        })->call($container);
    }
}
