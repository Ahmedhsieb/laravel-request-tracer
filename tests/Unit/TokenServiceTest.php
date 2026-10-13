<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Recording\TokenService;
use Illuminate\Http\Request;

function tokens(): TokenService
{
    return app(TokenService::class);
}

it('issues a token that verifies', function () {
    $issued = tokens()->issue(120);
    $payload = tokens()->verify($issued['token']);

    expect($payload)->not->toBeNull()
        ->and($payload['exp'])->toBe($issued['expires_at'])
        ->and($payload['exp'] - $payload['iat'])->toBe(120)
        ->and($issued['token'])->toStartWith('rt1.');
});

it('rejects an expired token', function () {
    $issued = tokens()->issue(60, now: 1_000_000);

    expect(tokens()->verify($issued['token'], null, 1_000_059))->not->toBeNull()
        ->and(tokens()->verify($issued['token'], null, 1_000_060))->toBeNull()          // exp is exclusive
        ->and(tokens()->verify($issued['token'], null, 2_000_000))->toBeNull();
});

it('rejects a token whose payload was tampered with', function () {
    $issued = tokens()->issue(60);
    [$prefix, $payload, $signature] = explode('.', $issued['token']);

    $forged = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
    $forged['exp'] += 86_400;                                                            // try to extend its life
    $forgedPayload = rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=');

    expect(tokens()->verify("{$prefix}.{$forgedPayload}.{$signature}"))->toBeNull();
});

it('rejects a token with a flipped signature bit, a truncated signature or another prefix', function () {
    $issued = tokens()->issue(60);
    [$prefix, $payload, $signature] = explode('.', $issued['token']);
    $flipped = $signature[0] === 'A' ? 'B'.substr($signature, 1) : 'A'.substr($signature, 1);

    expect(tokens()->verify("{$prefix}.{$payload}.{$flipped}"))->toBeNull()
        ->and(tokens()->verify("{$prefix}.{$payload}.".substr($signature, 0, 10)))->toBeNull()
        ->and(tokens()->verify("rt2.{$payload}.{$signature}"))->toBeNull()
        ->and(tokens()->verify("{$prefix}.{$payload}"))->toBeNull();
});

it('rejects garbage without throwing', function (string $garbage) {
    expect(tokens()->verify($garbage))->toBeNull();
})->with(['', 'x', '...', 'rt1..', 'rt1.!!!.???', str_repeat('a', 10_000), "rt1.e30.e30\n", 'null', '{"exp":99999999999}']);

it('rejects a token signed with another application key', function () {
    $issued = tokens()->issue(60);
    config(['app.key' => 'base64:'.base64_encode(str_repeat('z', 32))]);

    expect(tokens()->verify($issued['token']))->toBeNull();
});

it('caps the lifetime at one hour', function () {
    expect(tokens()->issue(999_999)['expires_at'] - time())->toBeLessThanOrEqual(3600)
        ->and(tokens()->issue(1)['expires_at'] - time())->toBeGreaterThanOrEqual(9);
});

it('restricts a token to a route pattern', function () {
    $issued = tokens()->issue(60, route: 'api/orders*');

    expect(tokens()->verify($issued['token'], Request::create('/api/orders/5')))->not->toBeNull()
        ->and(tokens()->verify($issued['token'], Request::create('/api/orders')))->not->toBeNull()
        ->and(tokens()->verify($issued['token'], Request::create('/api/users')))->toBeNull()
        ->and(tokens()->verify($issued['token'], Request::create('/admin')))->toBeNull();
});

it('carries the user id the token was issued for', function () {
    $payload = tokens()->verify(tokens()->issue(60, 42)['token']);

    expect($payload['uid'])->toBe('42');
});

it('refuses to sign anything without an application key', function () {
    config(['app.key' => '']);

    expect(fn () => tokens()->issue(60))->toThrow(RuntimeException::class);
    expect(tokens()->verify('rt1.a.b'))->toBeNull();
});
