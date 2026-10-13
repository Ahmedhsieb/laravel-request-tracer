<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Proxy;

/** A {@see MiddlewareDecorator} for middleware that defines `terminate($request, $response)`. */
final class TerminableMiddlewareDecorator extends MiddlewareDecorator
{
    public function terminate(mixed $request, mixed $response): mixed
    {
        return $this->forward('terminate', $request, $response);
    }
}
