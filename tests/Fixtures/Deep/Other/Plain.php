<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Deep\Other;

class Plain
{
    public function value(): int
    {
        return 1 > 0 ? 1 : 0;
    }
}
