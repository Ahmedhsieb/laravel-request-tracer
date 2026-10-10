<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Attr;

use RequestTracer\Attributes\Trace;

#[Trace]
class TracedClass
{
    public function one(): int
    {
        return 1;
    }

    public function two(): int
    {
        return $this->one() + 1;
    }
}
