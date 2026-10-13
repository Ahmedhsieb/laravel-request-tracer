<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Locked;

class PartlyLocked
{
    public function open(): string
    {
        return 'open';
    }

    final public function sealed(): string
    {
        return 'sealed';
    }
}
