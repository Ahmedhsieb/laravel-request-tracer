<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Attr;

use Ahmedhsieb\RequestTracer\Attributes\Trace;

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
