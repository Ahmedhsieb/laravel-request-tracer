<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RequestTracer\Http\Middleware\Authorize;
use RequestTracer\Http\Middleware\LightWeb;

it('puts the light web stack and the gate on the dashboard routes by default', function () {
    $route = app('router')->getRoutes()->getByName('request-tracer.api.meta');

    expect($route->gatherMiddleware())->toBe([LightWeb::class, Authorize::class]);
    Gate::define('viewRequestTracer', static fn (?object $user = null): bool => true);

    $this->getJson('/request-tracer/api/v1/meta')->assertOk();
});
