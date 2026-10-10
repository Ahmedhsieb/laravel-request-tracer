<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Proxy;

use Countable;
use LogicException;
use RuntimeException;
use Traversable;

#[MarkerAttribute('class-level', flag: true, list: ['a', 1])]
class EdgeCases
{
    public const LIMIT = 5;

    public function __construct(public readonly string $name = 'edge', private int $calls = 0) {}

    public function plain(int $a, string $b = 'x'): string
    {
        $this->calls++;

        return $a.$b.$this->name;
    }

    public function byRef(array &$items, int $n = self::LIMIT): int
    {
        $items[] = $n;

        return count($items);
    }

    public function variadic(string $sep, int ...$numbers): string
    {
        return implode($sep, $numbers);
    }

    public function variadicByRef(&...$refs): void
    {
        foreach ($refs as &$r) {
            $r = ($r ?? 0) + 1;
        }
    }

    public function nullable(?int $a, ?self $other = null): ?int
    {
        return $a === null ? $other?->calls : $a * 2;
    }

    public function union(int|string $v, array|bool|null $o = null): int|string
    {
        return is_int($v) ? $v + 1 : $v.'!';
    }

    public function intersection(Countable&Traversable $c): int
    {
        return count($c);
    }

    public function returnsSelf(): self
    {
        return $this;
    }

    public function returnsStatic(): static
    {
        return $this;
    }

    public function voidMethod(array &$out): void
    {
        $out[] = 1;
    }

    public function neverReturns(): never
    {
        throw new LogicException('never returns');
    }

    public function throws(string $why = 'boom'): void
    {
        throw new RuntimeException($why);
    }

    public function enumDefault(Suit $suit = Suit::Hearts): string
    {
        return $suit->value;
    }

    public function constDefaults(int $a = PHP_INT_MAX, string $b = DIRECTORY_SEPARATOR, float $c = 1.5, ?array $d = ['a' => 1, 'b' => [true, null]], string $e = self::class): string
    {
        return json_encode([$a, $b, $c, $d, $e]);
    }

    public function untyped($a, $b = null)
    {
        return [$a, $b];
    }

    public function mixedReturn(): mixed
    {
        return 42;
    }

    public static function staticMethod(): string
    {
        return 'static';
    }

    final public function finalMethod(): string
    {
        return 'final';
    }

    protected function protectedMethod(): string
    {
        return 'protected';
    }

    private function privateMethod(): string
    {
        return 'private';
    }

    #[MarkerAttribute('method', flag: false)]
    public function attributed(#[MarkerAttribute('param')] string $secret): string
    {
        return strrev($secret);
    }

    public function __invoke(int $x): int
    {
        return $x * 3;
    }

    public function generator(): \Generator
    {
        yield 1;
        yield 2;
    }

    public function newInInitializer(?object $o = new \stdClass): ?object
    {
        return $o;
    }

    public function callsPrivate(): string
    {
        return $this->privateMethod().$this->protectedMethod();
    }
}
