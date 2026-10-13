<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard's default "web" stack: only the part of the application's `web` group that the dashboard needs (cookies, session,
 * CSRF), not the middleware the application added to it (permission sync, locale, "last seen", debug bars, ...).
 *
 * The dashboard polls its API every few seconds. Behind a heavy `web` group each call paid the application's own middleware
 * (2.9 s against 12 ms in one real project), and the application's side effects ran on every poll. Middleware that extends the
 * framework's own ones (an `App\Http\Middleware\EncryptCookies`, a customised `VerifyCsrfToken`) are kept. Set
 * `dashboard.middleware` to `['web', ...]` to use the whole group instead.
 */
final class LightWeb
{
    /** Framework middleware the dashboard needs. A class that extends one of these is kept as well. */
    private const KEEP = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        AuthenticateSession::class,
        VerifyCsrfToken::class,
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
    ];

    public function __construct(private readonly Router $router, private readonly Application $app) {}

    public function handle(Request $request, Closure $next): Response
    {
        $stack = self::stack($this->router->getMiddlewareGroups()['web'] ?? []);
        if ($stack === []) {
            return $next($request);
        }

        /** @var Response */
        return (new Pipeline($this->app))->send($request)->through($stack)->then($next);
    }

    /**
     * @param  array<int, mixed>  $group
     * @return list<string>
     */
    public static function stack(array $group): array
    {
        $kept = [];
        foreach ($group as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $class = explode(':', $entry, 2)[0];
            if (! class_exists($class)) {
                continue;
            }
            foreach (self::KEEP as $framework) {
                if (is_a($class, $framework, true)) {
                    $kept[] = $entry;
                    break;
                }
            }
        }

        return $kept;
    }
}
