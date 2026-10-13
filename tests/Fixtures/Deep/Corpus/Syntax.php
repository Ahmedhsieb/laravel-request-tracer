<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Deep\Corpus;

use Attribute;
use LogicException;
use RuntimeException;

#[Attribute(Attribute::TARGET_ALL)]
final class Flag
{
    public function __construct(public readonly string $name = 'x') {}
}

enum Level: int
{
    case Low = 1;
    case High = 10;

    public const DEFAULT = self::Low;

    public static function fromScore(int $score): self
    {
        return $score > 5 ? self::High : self::Low;
    }

    public function label(): string
    {
        return match ($this) {
            self::Low => 'low',
            self::High => 'high',
        };
    }
}

#[Flag('class')]
class Syntax
{
    public const LIMIT = PHP_INT_SIZE > 4 ? 64 : 32;

    private static int $counter = 0;

    public function __construct(
        #[Flag('ctor')] public readonly int $base = 10,
        private readonly array $opts = ['a' => PHP_INT_SIZE > 4 ? 1 : 2],
        private ?Level $level = null,
    ) {}

    #[Flag('method')]
    public function run(): array
    {
        $out = [];
        $out[] = $this->named(b: 2, a: 1);
        $out[] = $this->heredoc('World', ['k' => 'v']);
        $fn = strtoupper(...);
        $out[] = $fn('first-class');
        $m = $this->named(...);
        $out[] = $m(5, 6);
        $out[] = Level::fromScore(9)->label();
        $out[] = $this->nullsafe(null);
        $out[] = $this->statics();
        $out[] = $this->statics();
        $out[] = implode('', iterator_to_array($this->gen(3), false));
        [$a, [$b, $c]] = [1, [2, 3]];
        ['x' => $x, 'y' => $y] = ['x' => 'X', 'y' => 'Y'];
        $out[] = "$a$b$c$x$y";
        $out[] = $this->byRef([3, 1, 2]);
        $out[] = (static fn (int $n): int => $n > 2 ? $n * 2 : $n)(3);
        $out[] = $this->tryCatch('boom');
        $out[] = $this->tryCatch('');
        $out[] = $this->noVariableCatch(true);
        $out[] = $this->noVariableCatch(false);
        $out[] = self::LIMIT;
        $out[] = $this->base + $this->opts['a'];

        return $out;
    }

    public function named(int $a, int $b = 0): int
    {
        return $a * 10 + $b;
    }

    public function heredoc(string $who, array $data): string
    {
        $text = <<<EOT
        Hello {$who}!
          data: {$data['k']}
        EOT;
        $raw = <<<'RAW'
        no $interpolation {here}
        RAW;
        if ($text === <<<TXT
        Hello World!
          data: v
        TXT) {
            return $text.'|'.$raw.'|same';
        }

        return $text;
    }

    public function nullsafe(?object $o): string
    {
        return $o?->name ?? 'none';
    }

    public function statics(): int
    {
        static $n = PHP_INT_SIZE > 4 ? 0 : 1;
        $n++;

        return $n + self::$counter;
    }

    public function gen(int $n): \Generator
    {
        for ($i = 0; $i < $n; $i++) {
            yield $i * 2;
        }

        return 'done';
    }

    public function byRef(array $a): string
    {
        $sorted = $a;
        sort($sorted);
        $f = function (array &$arr) {
            $arr[] = 99;
        };
        $f($sorted);

        return implode(',', $sorted);
    }

    public function tryCatch(string $msg): string
    {
        try {
            if ($msg === '') {
                return 'empty';
            }
            throw new RuntimeException($msg);
        } catch (LogicException | RuntimeException $e) {
            return 'caught:'.$e->getMessage();
        } finally {
            self::$counter++;
        }
    }

    public function noVariableCatch(bool $throw): string
    {
        try {
            if ($throw) {
                throw new LogicException('x');
            }
        } catch (LogicException) {
        }

        return $throw ? 'swallowed' : 'clean';
    }
}
