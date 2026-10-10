<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Services;

use RequestTracer\Attributes\NoTrace;

class CountingService
{
    public int $count = 0;

    public function increment(): int
    {
        return ++$this->count;
    }

    #[NoTrace]
    public function quiet(): int
    {
        return ++$this->count;
    }
}
