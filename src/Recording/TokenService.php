<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Recording;

use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Signed recording tokens for the `X-Request-Tracer` header.
 *
 *   token   = "rt1." base64url(payload) "." base64url(HMAC-SHA256(signing key, "rt1." base64url(payload)))
 *   payload = {"iat": issued, "exp": expiry, "uid": optional user id, "route": optional path pattern}
 *
 * The signing key is derived from the application key, so tokens are valid on every server that shares it and die when it
 * is rotated. A token is only an *authorisation to record one's own requests*: it never grants access to stored traces
 * (that is the gate's job). Invalid tokens are ignored silently (debug log only), so probing the header teaches nothing.
 */
final class TokenService
{
    private const PREFIX = 'rt1';

    public const MAX_TTL = 3600;

    /**
     * @return array{token: string, expires_at: int}
     */
    public function issue(?int $ttl = null, string|int|null $userId = null, ?string $route = null, ?int $now = null): array
    {
        $default = (int) App::config()->get('request-tracer.triggers.token_ttl', 300);
        $ttl = max(10, min(self::MAX_TTL, $ttl ?? $default));
        $now ??= time();

        $payload = ['iat' => $now, 'exp' => $now + $ttl];
        if ($userId !== null && $userId !== '') {
            $payload['uid'] = (string) $userId;
        }
        if ($route !== null && $route !== '') {
            $payload['route'] = ltrim($route, '/');
        }

        $body = self::PREFIX.'.'.$this->encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return ['token' => $body.'.'.$this->encode($this->sign($body)), 'expires_at' => $payload['exp']];
    }

    /**
     * @return array{iat: int, exp: int, uid?: string, route?: string}|null the payload of a valid token for this request, else null
     */
    public function verify(string $token, ?Request $request = null, ?int $now = null): ?array
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
                $this->reject('malformed');

                return null;
            }
            $body = $parts[0].'.'.$parts[1];
            $signature = $this->decode($parts[2]);
            if ($signature === null || ! hash_equals($this->sign($body), $signature)) {
                $this->reject('bad signature');

                return null;
            }

            $json = $this->decode($parts[1]);
            $payload = $json === null ? null : json_decode($json, true);
            if (! is_array($payload) || ! isset($payload['exp'], $payload['iat']) || ! is_int($payload['exp']) || ! is_int($payload['iat'])) {
                $this->reject('bad payload');

                return null;
            }
            $now ??= time();
            if ($payload['exp'] <= $now) {
                $this->reject('expired');

                return null;
            }
            if (self::MAX_TTL + 5 < $payload['exp'] - $payload['iat'] || $payload['iat'] > $now + 60) {
                $this->reject('lifetime out of range');

                return null;
            }
            if (isset($payload['route']) && $request !== null) {
                $path = ltrim($request->path(), '/');
                if (! is_string($payload['route']) || ! Str::is($payload['route'], $path === '' ? '/' : $path)) {
                    $this->reject('route mismatch');

                    return null;
                }
            }
            if (isset($payload['uid']) && ! is_string($payload['uid'])) {
                $this->reject('bad payload');

                return null;
            }

            $valid = ['iat' => $payload['iat'], 'exp' => $payload['exp']];
            if (isset($payload['uid'])) {
                $valid['uid'] = $payload['uid'];
            }
            if (isset($payload['route']) && is_string($payload['route'])) {
                $valid['route'] = $payload['route'];
            }

            return $valid;
        } catch (\Throwable $e) {
            Failure::report($e, 'verifying recording token');

            return null;
        }
    }

    private function reject(string $why): void
    {
        Failure::debug('recording token ignored: '.$why);
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key(), true);
    }

    private function key(): string
    {
        $appKey = (string) App::config()->get('app.key', '');
        if ($appKey === '') {
            throw new \RuntimeException('The application key is empty; signed recording tokens need APP_KEY.');
        }
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            $appKey = $decoded === false ? $appKey : $decoded;
        }

        return hash_hmac('sha256', 'request-tracer.token.v1', $appKey, true);
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function decode(string $encoded): ?string
    {
        if ($encoded === '' || preg_match('/^[A-Za-z0-9_-]+$/', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return $raw === false ? null : $raw;
    }
}
