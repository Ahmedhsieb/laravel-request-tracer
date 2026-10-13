<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Replay\ReplayRequest;

it('understands "POST /orders" and a bare path', function () {
    expect(ReplayRequest::fromLine('post /orders?x=1', '{"a":1}', 42)->toArray())->toMatchArray(['method' => 'POST', 'url' => '/orders?x=1', 'body' => '{"a":1}', 'as_user' => 42])
        ->and(ReplayRequest::fromLine('/orders')->method)->toBe('GET');
});

it('builds a JSON request the application reads as JSON', function () {
    config(['app.url' => 'https://shop.test']);

    $request = (new ReplayRequest('POST', '/api/orders?page=2', ['Authorization' => 'Bearer t'], '{"items":[1,2]}'))->toRequest();

    expect($request->getMethod())->toBe('POST')
        ->and($request->fullUrl())->toBe('https://shop.test/api/orders?page=2')
        ->and($request->isJson())->toBeTrue()
        ->and($request->json('items'))->toBe([1, 2])
        ->and($request->bearerToken())->toBe('t')
        ->and($request->query('page'))->toBe('2');
});

it('builds a form request from urlencoded data and reads the cookies of a pasted cURL', function () {
    $request = (new ReplayRequest('POST', 'https://other.test/login', ['Content-Type' => 'application/x-www-form-urlencoded', 'Cookie' => 'a=1; b=two%20words'], 'email=a%40b.test&remember=1'))->toRequest();

    expect($request->getHost())->toBe('other.test')
        ->and($request->input('email'))->toBe('a@b.test')
        ->and($request->cookie('b'))->toBe('two words')
        ->and($request->headers->has('cookie'))->toBeFalse();
});

it('guesses form data when a body has no content type and does not look like JSON', function () {
    $request = (new ReplayRequest('POST', '/x', [], 'a=1&b=2'))->toRequest();

    expect($request->input('b'))->toBe('2');
});
