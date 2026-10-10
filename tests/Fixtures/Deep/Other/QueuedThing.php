<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Deep\Other;

class QueuedThing
{
    public function decide(int $n): string
    {
        if ($n % 2 === 0) {
            return 'even';
        }

        return 'odd';
    }
}
