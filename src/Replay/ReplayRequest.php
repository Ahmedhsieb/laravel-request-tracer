<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Replay;

use Illuminate\Http\Request;

/** A request to run again: what the dashboard form, a pasted cURL command or `request-tracer:replay` describe. */
final class ReplayRequest
{
    /**
     * @param  string  $url  absolute (`https://shop.test/orders?page=2`) or a path (`/orders?page=2`, resolved against `app.url`)
     * @param  array<string, string>  $headers  name => value
     * @param  string|int|null  $asUser  the id of the user to act as (no login needed)
     * @param  string  $mode  `simulate` (nothing is changed) or `real` (the request does what it does; what can be undone can be taken back)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers = [],
        public readonly ?string $body = null,
        public readonly string|int|null $asUser = null,
        public readonly string $mode = self::SIMULATE,
    ) {}

    public const SIMULATE = 'simulate';

    public const REAL = 'real';

    /** "POST /orders" or just "/orders" (GET). */
    public static function fromLine(string $line, ?string $body = null, string|int|null $asUser = null): self
    {
        $line = trim($line);
        if (preg_match('~^([A-Za-z]+)\s+(\S+)$~', $line, $m) === 1) {
            return new self(strtoupper($m[1]), $m[2], [], $body, $asUser);
        }

        return new self($body === null ? 'GET' : 'POST', $line, [], $body, $asUser);
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $headers = [];
        foreach ((array) ($data['headers'] ?? []) as $name => $value) {
            if (is_string($name) && (is_string($value) || is_numeric($value))) {
                $headers[$name] = (string) $value;
            }
        }
        $as = $data['as_user'] ?? $data['asUser'] ?? null;
        $body = $data['body'] ?? null;

        return new self(
            strtoupper(is_string($data['method'] ?? null) && $data['method'] !== '' ? $data['method'] : 'GET'),
            (string) ($data['url'] ?? '/'),
            $headers,
            is_string($body) && $body !== '' ? $body : null,
            is_string($as) || is_int($as) ? ($as === '' ? null : $as) : null,
            ($data['mode'] ?? null) === self::REAL ? self::REAL : self::SIMULATE,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['method' => $this->method, 'url' => $this->url, 'headers' => $this->headers, 'body' => $this->body, 'as_user' => $this->asUser, 'mode' => $this->mode];
    }

    public function withUser(string|int|null $asUser): self
    {
        return new self($this->method, $this->url, $this->headers, $this->body, $asUser, $this->mode);
    }

    public function withMode(string $mode): self
    {
        return new self($this->method, $this->url, $this->headers, $this->body, $this->asUser, $mode === self::REAL ? self::REAL : self::SIMULATE);
    }

    public function isReal(): bool
    {
        return $this->mode === self::REAL;
    }

    /** The Illuminate request that will be handled by the application's HTTP kernel. */
    public function toRequest(): Request
    {
        $url = preg_match('~^https?://~i', $this->url) === 1
            ? $this->url
            : rtrim((string) config('app.url', 'http://localhost'), '/').'/'.ltrim($this->url, '/');

        $headers = [];
        foreach ($this->headers as $name => $value) {
            $headers[strtolower($name)] = $value;
        }

        $cookies = [];
        foreach (explode(';', $headers['cookie'] ?? '') as $pair) {
            [$key, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
            if ($key !== '') {
                $cookies[$key] = urldecode($value);
            }
        }
        unset($headers['cookie']);

        $server = [];
        $contentType = $headers['content-type'] ?? null;
        $parameters = [];
        $content = null;
        if ($this->body !== null) {
            if ($contentType !== null && stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                parse_str($this->body, $parameters);
            } elseif ($contentType === null && ! str_starts_with(ltrim($this->body), '{') && ! str_starts_with(ltrim($this->body), '[')) {
                parse_str($this->body, $parameters);
                $headers['content-type'] = 'application/x-www-form-urlencoded';
            } else {
                $content = $this->body;
                $headers['content-type'] ??= 'application/json';
            }
        }
        if (isset($headers['content-type'])) {
            $server['CONTENT_TYPE'] = $headers['content-type'];
        }

        $request = Request::create($url, $this->method, $parameters, $cookies, [], $server, $content);
        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }
        if (! isset($headers['accept']) && $content !== null) {
            $request->headers->set('Accept', 'application/json');
        }

        return $request;
    }
}
