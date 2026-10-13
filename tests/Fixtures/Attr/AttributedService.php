<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Attr;

use Ahmedhsieb\RequestTracer\Attributes\Trace;

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
