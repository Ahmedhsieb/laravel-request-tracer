<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Attr;

use RequestTracer\Attributes\Trace;

class AttributedService
{
    #[Trace(label: 'Custom label')]
    public function traced(int $n): int
    {
        return $n + 1;
    }

    public function untraced(int $n): int
    {
        return $n + 2;
    }
}
