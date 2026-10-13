<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Proxy;

use Ahmedhsieb\RequestTracer\Span;
use Ahmedhsieb\RequestTracer\SpanFlag;
use Ahmedhsieb\RequestTracer\SpanType;
use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Ahmedhsieb\RequestTracer\Support\Summary;
use Ahmedhsieb\RequestTracer\Tracer;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the generated proxy subclasses call. Every method is safe: a failure inside the tracer is logged and swallowed,
 * and only the application's own exceptions ever propagate (they are re-thrown by the generated code).
 */
final class Runtime
{
    public const SERVICE = 0;

    public const MIDDLEWARE = 1;

    /**
     * @param  array<string, mixed>  $args  argument name => value
     */
    public static function enter(string $label, ?string $file, ?int $line, int $kind, array $args): ?Span
    {
        $tracer = Tracer::$instance;
        if ($tracer === null || ! $tracer->on) {
            return null;
        }

        try {
            $session = $tracer->session();
            if ($session === null) {
                return null;
            }
            // The action of a controller is already a step of its own (the `controller` step). A controller that Laravel does not know as one
            // (since Laravel 11 the base controller extends nothing) is proxied as well, which would show the same call twice and keep the
            // lines of the action on the copy: fold it into the controller step, which then keeps its arguments too.
            $open = $session->recorder->current();
            if ($kind === self::SERVICE && $open !== null && $open->type === SpanType::Controller && ! $open->ghost && $file !== null && $open->file === $file && $open->line === $line) {
                $route = App::container()->make('request')->route();
                $open->input = $session->dump(['route' => $route instanceof Route ? $route->uri() : null, 'parameters' => $route instanceof Route ? $route->parameters() : [], 'arguments' => $args]);

                return null;
            }
            $span = $session->recorder->begin($kind === self::MIDDLEWARE ? SpanType::Middleware : SpanType::Service, $label, $file, $line);
            if (! $span->ghost && $kind === self::SERVICE && $args !== []) {
                $span->input = $session->dump($args);
            }

            return $span;
        } catch (\Throwable $e) {
            Failure::report($e, 'proxy enter');

            return null;
        }
    }

    public static function leave(?Span $span, mixed $value, bool $void = false): void
    {
        if ($span === null) {
            return;
        }
        $tracer = Tracer::$instance;
        try {
            $session = $tracer?->session();
            if ($session === null) {
                return;
            }
            if (! $span->ghost && ! $void) {
                $span->output = $session->dump($value instanceof Response && $span->type === SpanType::Middleware ? ['status' => $value->getStatusCode()] : $value);
                $span->meta['summary'] = 'Returns '.Summary::of($value);
            } elseif (! $span->ghost && $void) {
                $span->meta['summary'] = 'Returns nothing';
            }
            $session->recorder->end($span);
        } catch (\Throwable $e) {
            Failure::report($e, 'proxy leave');
        }
    }

    public static function fail(?Span $span, \Throwable $exception): void
    {
        if ($span === null) {
            return;
        }
        $tracer = Tracer::$instance;
        try {
            $session = $tracer?->session();
            if ($session === null) {
                return;
            }
            $session->exceptions()->record($exception);
            if (! $span->ghost) {
                // Failed until we know the exception was caught further up (decided once the request ended).
                $span->flag = SpanFlag::Failed;
                $span->meta['exception'] = $session->exceptions()->idOf($exception);
                $span->meta['summary'] = 'Threw '.Summary::of($exception);
                $span->output = $session->dump($exception);
            }
            $session->recorder->end($span);
        } catch (\Throwable $e) {
            Failure::report($e, 'proxy fail');
        }
    }
}
