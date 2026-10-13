<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Proxy;

/** Outcome of generating (or loading) the proxy for one class. */
final class ProxyResult
{
    /**
     * @param  class-string|null  $proxyClass  fully qualified proxy class, or null when the class cannot be proxied
     * @param  list<string>  $notes  "not traceable: ..." messages for the dashboard
     * @param  int  $methods  number of overridden methods
     */
    public function __construct(
        public readonly ?string $proxyClass,
        public readonly array $notes = [],
        public readonly int $methods = 0,
    ) {}
}
