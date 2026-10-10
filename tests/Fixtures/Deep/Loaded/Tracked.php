<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Deep\Loaded;

class Tracked
{
    public function grade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        } elseif ($score >= 50) {
            return 'B';
        }

        return 'F';
    }

    public function file(): string
    {
        return __FILE__;
    }
}
