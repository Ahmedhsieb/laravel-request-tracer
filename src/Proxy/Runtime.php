<?php

declare(strict_types=1);

namespace RequestTracer\Proxy;

use RequestTracer\Span;
use RequestTracer\SpanFlag;
use RequestTracer\SpanType;
use RequestTracer\Support\Failure;
use RequestTracer\Tracer;
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
                $span->output = $session->dump($exception);
            }
            $session->recorder->end($span);
        } catch (\Throwable $e) {
            Failure::report($e, 'proxy fail');
        }
    }
}
