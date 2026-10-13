<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Replay\CurlParser;

it('reads a POST that Chrome copies as cURL (bash), quotes, escapes and all', function () {
    $command = <<<'CURL'
curl 'https://shop.test/api/orders?page=2' \
  -H 'accept: application/json, text/plain, */*' \
  -H 'content-type: application/json' \
  -H 'authorization: Bearer abc.def' \
  -H 'sec-ch-ua: "Chromium";v="120"' \
  -H 'accept-encoding: gzip, deflate' \
  -b 'laravel_session=eyJpdiI6; XSRF-TOKEN=eyJ2' \
  --data-raw $'{"note":"it\'s fine","qty":2}' \
  --compressed
CURL;

    $request = CurlParser::parse($command);

    expect($request->method)->toBe('POST')
        ->and($request->url)->toBe('https://shop.test/api/orders?page=2')
        ->and($request->body)->toBe('{"note":"it\'s fine","qty":2}')
        ->and($request->headers)->toMatchArray(['accept' => 'application/json, text/plain, */*', 'content-type' => 'application/json', 'authorization' => 'Bearer abc.def', 'Cookie' => 'laravel_session=eyJpdiI6; XSRF-TOKEN=eyJ2'])
        ->and(array_keys($request->headers))->not->toContain('sec-ch-ua')              // browser noise is dropped
        ->and(array_keys($request->headers))->not->toContain('accept-encoding');
});

it('uses GET without data, POST with data, and -X wins over both', function () {
    expect(CurlParser::parse('curl https://a.test/x')->method)->toBe('GET')
        ->and(CurlParser::parse("curl https://a.test/x -d 'a=1'")->method)->toBe('POST')
        ->and(CurlParser::parse("curl -X PUT https://a.test/x -d 'a=1'")->method)->toBe('PUT')
        ->and(CurlParser::parse('curl -I https://a.test/x')->method)->toBe('HEAD');
});

it('joins several --data options and understands --option=value', function () {
    $request = CurlParser::parse("curl --request=PATCH --header='X-One: 1' --url=https://a.test/x -d 'a=1' --data b=2");

    expect($request->method)->toBe('PATCH')
        ->and($request->url)->toBe('https://a.test/x')
        ->and($request->headers)->toBe(['X-One' => '1'])
        ->and($request->body)->toBe('a=1&b=2');
});

it('does not take the value of an option it ignores for the URL', function () {
    $request = CurlParser::parse('curl -o /tmp/out.html --max-time 5 https://a.test/x -s -k -L');

    expect($request->url)->toBe('https://a.test/x');
});

it('turns -u into a Basic authorization header', function () {
    expect(CurlParser::parse('curl -u ann:secret https://a.test/x')->headers['Authorization'])->toBe('Basic '.base64_encode('ann:secret'));
});

it('refuses what is not a cURL command or has no URL', function () {
    expect(fn () => CurlParser::parse('wget https://a.test/x'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CurlParser::parse("curl -H 'a: b'"))->toThrow(InvalidArgumentException::class)
        ->and(fn () => CurlParser::parse(''))->toThrow(InvalidArgumentException::class);
});
