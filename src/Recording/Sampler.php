<?php

declare(strict_types=1);

namespace RequestTracer\Recording;

/** Random sampling for `triggers.sampling.rate` (0..1). */
final class Sampler
{
    public static function hit(float $rate): bool
    {
        if ($rate <= 0) {
            return false;
        }
        if ($rate >= 1) {
            return true;
        }

        return random_int(0, 999_999) < (int) round($rate * 1_000_000);
    }
}
