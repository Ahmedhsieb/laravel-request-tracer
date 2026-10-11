<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Http;

use Closure;
use Illuminate\Http\Request;

/** Registered in the container with a closure (like Laravel's own StartSession), so no subclass can be generated for it. */
class ClosureBoundMiddleware
{
    public static int $terminated = 0;

    public function __construct(private readonly string $greeting) {}

    public function handle(Request $request, Closure $next, string $suffix = '!')
    {
        $response = $next($request);
        $response->headers->set('X-Closure', $this->greeting.$suffix);

        return $response;
    }

    public function terminate($request, $response): void
    {
        self::$terminated++;
    }
}
