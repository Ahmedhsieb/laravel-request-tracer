<?php

declare(strict_types=1);

use RequestTracer\Http\Middleware\Authorize;
use RequestTracer\Http\Middleware\LightWeb;
use RequestTracer\RequestTracerServiceProvider;
use RequestTracer\Support\ConfigMerger;

function defaults(): array
{
    return require dirname(__DIR__, 2).'/config/request-tracer.php';
}

it('adds a prefix to the excluded paths without losing the built-in ones', function () {
    $config = ConfigMerger::merge(defaults(), ['exclude' => ['paths' => ['admin/*', 'api/internal/*']]]);

    expect($config['exclude']['paths'])->toContain('admin/*', 'api/internal/*', 'login', 'webhooks/stripe*', 'health*', 'request-tracer*')
        ->and($config['exclude']['commands'])->toContain('queue:work', 'schedule:run', 'request-tracer:*')       // the rest of the section is untouched
        ->and($config['exclude']['routes'])->toBe([]);
});

it('takes a default away again with a leading "!"', function () {
    $config = ConfigMerger::merge(defaults(), ['exclude' => ['paths' => ['!login', '!register', 'admin/*']]]);

    expect($config['exclude']['paths'])->not->toContain('login')->not->toContain('register')
        ->and($config['exclude']['paths'])->toContain('admin/*', 'password/*');
});

it('adds to the masking keys instead of replacing them, so a secret is never unmasked by accident', function () {
    $config = ConfigMerger::merge(defaults(), ['masking' => ['keys' => ['ssn', 'national_id']]]);

    expect($config['masking']['keys'])->toContain('password', 'token', 'authorization', 'ssn', 'national_id')
        ->and($config['masking']['mask'])->toBe('••••••••')
        ->and($config['masking']['value_patterns'])->not->toBeEmpty();
});

it('replaces the lists that say what to look at: narrowing code.paths means "only these"', function () {
    $config = ConfigMerger::merge(defaults(), ['code' => ['paths' => ['modules/Billing'], 'except' => ['app/Legacy']]]);

    expect($config['code']['paths'])->toBe(['modules/Billing'])
        ->and($config['code']['except'])->toBe(['app/Legacy'])
        ->and($config['code']['line_by_line'])->toBeTrue();
});

it('merges nested sections down to the last key', function () {
    $config = ConfigMerger::merge(defaults(), ['triggers' => ['sampling' => ['enabled' => true, 'rate' => 0.2]], 'dashboard' => ['path' => 'tracer']]);

    expect($config['triggers']['sampling'])->toBe(['enabled' => true, 'rate' => 0.2, 'require_auth' => true])
        ->and($config['triggers']['header'])->toBe('X-Request-Tracer')
        ->and($config['triggers']['watch_list']['enabled'])->toBeTrue()
        ->and($config['dashboard']['path'])->toBe('tracer')
        ->and($config['dashboard']['gate'])->toBe('viewRequestTracer');
});

it('lets a scalar be set to anything and an empty section mean "nothing set"', function () {
    $config = ConfigMerger::merge(defaults(), ['enabled' => false, 'mode' => 'always', 'code' => [], 'storage' => ['queue' => ['enabled' => true]]]);

    expect($config['enabled'])->toBeFalse()
        ->and($config['mode'])->toBe('always')
        ->and($config['code']['paths'])->toBe(['app'])
        ->and($config['storage']['queue'])->toBe(['enabled' => true, 'connection' => null, 'queue' => null]);
});

it('does not repeat an entry that is already a default', function () {
    $config = ConfigMerger::merge(defaults(), ['exclude' => ['paths' => ['login', 'admin/*', 'admin/*']]]);

    expect(array_count_values($config['exclude']['paths']))->each->toBe(1);
});

it('keeps the gate on the dashboard whatever middleware the config lists', function () {
    $provider = app()->getProvider(RequestTracerServiceProvider::class);
    $middleware = fn (array $config): array => (fn () => $this->dashboardMiddleware($config))->call($provider);

    expect($middleware(['middleware' => ['web']]))->toBe(['web', Authorize::class])
        ->and($middleware(['middleware' => ['web', 'auth', Authorize::class]]))->toBe(['web', 'auth', Authorize::class])
        ->and($middleware([]))->toBe([LightWeb::class, Authorize::class])
        ->and($middleware(['middleware' => null]))->toBe([LightWeb::class, Authorize::class]);
});
