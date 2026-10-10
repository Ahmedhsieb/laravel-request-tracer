<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Deep\Loaded;

class FallbackThing
{
    public function twice(int $n): int
    {
        return $n > 0 ? $n * 2 : 0;
    }
}
