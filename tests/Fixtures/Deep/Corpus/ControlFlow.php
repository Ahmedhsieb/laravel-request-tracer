<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Deep\Corpus;

class ControlFlow
{
    public function run(): array
    {
        $out = [];
        foreach ([-5, 0, 7, 300, 1000] as $n) {
            $out[] = $this->classify($n);
        }
        $out[] = $this->braceless(3);
        $out[] = $this->braceless(-3);
        $out[] = $this->nested(1, 2);
        $out[] = $this->nested(2, 1);
        $out[] = $this->nested(2, 2);
        $out[] = $this->loops(4);
        $out[] = $this->ternaries(null);
        $out[] = $this->ternaries('x');
        $out[] = $this->logic(true, false);
        $out[] = $this->logic(false, true);
        $out[] = $this->comparisons(3, 3.0);
        $out[] = $this->alt(5);
        $out[] = $this->sw('b');
        $out[] = $this->sw('zzz');

        return $out;
    }

    public function classify(int $n): string
    {
        if ($n < 0) {
            return 'negative';
        } elseif ($n === 0) {
            return 'zero';
        } elseif ($n > 0 && $n < 10) {
            return 'small';
        } elseif (($n) >= (10) and $n <= 500) {
            return 'medium';
        } else {
            return 'large';
        }
    }

    public function braceless(int $n): string
    {
        if ($n > 0) return 'pos'; else return 'neg';
    }

    public function nested(int $a, int $b): string
    {
        if ($a > $b)
            if ($b > 0)
                return 'both';
            else
                return 'a only';
        else if ($a === $b)
            return 'equal';
        else
            return 'b bigger';
    }

    public function loops(int $n): int
    {
        $sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $sum += $i;
        }
        $j = 0;
        while ($j < $n) $j++;
        do {
            $j--;
        } while ($j > 1);
        foreach ([1, 2, 3] as $k => $v) $sum += $k * $v;
        foreach (['a' => 1] as $key => &$ref) { $ref = $ref + 1; }
        unset($ref);

        return $sum + $j;
    }

    public function ternaries(?string $s): string
    {
        $a = $s ?? 'default';
        $b = $s === null ? 'null' : ($s === 'x' ? 'is x' : 'other');
        $c = $s ?: 'falsy';
        $d = (isset($s) && $s !== '') ? strtoupper($s) : 'none';

        return "$a|$b|$c|$d";
    }

    public function logic(bool $p, bool $q): string
    {
        $r = [];
        $r[] = $p && $q ? 'and' : 'not-and';
        $r[] = ($p || $q) ? 'or' : 'not-or';
        $r[] = (!$p and $q) ? 'np-and-q' : 'other';
        $r[] = ($p xor $q) ? 'xor' : 'same';
        if (!($p && $q)) { $r[] = 'neg'; }

        return implode(',', $r);
    }

    public function comparisons(int $i, float $f): string
    {
        $r = [];
        if ($i == $f) $r[] = 'eq';
        if ($i === 3) $r[] = 'id';
        if ($i !== $f) $r[] = 'nid';
        if ($i <> 4) $r[] = 'ne';
        if ($i != 4) $r[] = 'ne2';
        if (($i + 1) * 2 > $f) $r[] = 'expr';
        if ($i <=> $f) { $r[] = 'cmp'; } else { $r[] = 'spaceship0'; }
        if ($i instanceof \stdClass) { $r[] = 'obj'; }

        return implode(',', $r);
    }

    public function alt(int $n): string
    {
        $out = '';
        if ($n > 3):
            $out .= 'big';
        elseif ($n > 1):
            $out .= 'mid';
        else:
            $out .= 'small';
        endif;
        foreach ([1, 2] as $x):
            $out .= $x;
        endforeach;
        for ($i = 0; $i < 2; $i++):
            $out .= '.';
        endfor;
        while ($n-- > 3):
            $out .= '!';
        endwhile;

        return $out;
    }

    public function sw(string $x): string
    {
        switch ($x) {
            case 'a':
                return 'A';
            case 'b':
            case 'c':
                $y = 'B or C';
                break;
            default:
                $y = 'other';
        }

        return match (true) {
            $x === 'b' => $y.'!',
            default => $y,
        };
    }
}
