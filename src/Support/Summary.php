<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * One short human sentence fragment about a value ("Order #1057", "array (3)", "201 JsonResponse"), computed while the
 * value is still at hand. The dashboard shows it as the subtitle of a step ("Returns Order #1057") without loading the
 * step's payload. Never reads anything that could have side effects (no accessors, no lazy relations, no iterators).
 */
final class Summary
{
    private const MAX = 70;

    public static function of(mixed $value): string
    {
        return self::clip(match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => "'".str_replace(["\r", "\n"], ' ', mb_substr($value, 0, 40)).(mb_strlen($value) > 40 ? '…' : '')."'",
            is_array($value) => 'array ('.count($value).')',
            $value instanceof Model => Locator::shortClass($value::class).(is_scalar($value->getKey()) ? ' #'.$value->getKey() : ''),
            $value instanceof Collection => 'Collection ('.$value->count().')',
            $value instanceof Response => $value->getStatusCode().' '.Locator::shortClass($value::class),
            $value instanceof \Throwable => Locator::shortClass($value::class).': '.$value->getMessage(),
            $value instanceof \UnitEnum => Locator::shortClass($value::class).'::'.$value->name,
            $value instanceof \Closure => 'Closure',
            $value instanceof \Generator => 'Generator',
            is_object($value) => Locator::shortClass($value::class),
            default => get_debug_type($value),
        });
    }

    private static function clip(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > self::MAX ? mb_substr($text, 0, self::MAX - 1).'…' : $text;
    }
}
