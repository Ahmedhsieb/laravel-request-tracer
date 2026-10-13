<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Recording\Listening;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

it('opens the dashboard to anyone in the local environment (no gate needed)', function () {
    expect(app()->environment('local'))->toBeTrue();

    $this->get('/request-tracer')->assertOk();
    $this->getJson('/request-tracer/api/v1/meta')->assertOk()->assertJsonPath('is_production', false)->assertJsonPath('mode', 'triggered');
});

it('records nothing by default, not even locally, until somebody listens', function () {
    Route::get('/anything', fn () => 'ok');
    $this->get('/anything')->assertOk();
    expect($this->tracerTable('traces')->count())->toBe(0);

    $file = sys_get_temp_dir().'/rt-local-'.uniqid();
    Listening::useFile($file);
    try {
        Listening::beat();
        $this->get('/anything')->assertOk();
    } finally {
        @unlink($file);
        Listening::useFile(null);
    }

    expect($this->tracerTable('traces')->where('path', '/anything')->count())->toBe(1);
});

it('lets an application gate override the permissive local default', function () {
    Gate::define('viewRequestTracer', fn (?Authenticatable $user = null) => false);

    $this->get('/request-tracer')->assertForbidden();
});
