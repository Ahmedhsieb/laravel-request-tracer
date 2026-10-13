<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Http\Middleware\LightWeb;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;

class AppEncryptCookies extends EncryptCookies {}

class AppLastSeen
{
    public function handle($request, $next)
    {
        return $next($request);
    }
}

it('keeps the framework middleware of the web group and the application\'s subclasses of them', function () {
    $stack = LightWeb::stack([AppEncryptCookies::class, StartSession::class, AppLastSeen::class, 'throttle:60,1', 'does-not-exist']);

    expect($stack)->toBe([AppEncryptCookies::class, StartSession::class]);
});

it('drops closures, aliases and the middleware an application added to the group', function () {
    expect(LightWeb::stack([fn () => null, 'auth', AppLastSeen::class]))->toBe([]);
});
