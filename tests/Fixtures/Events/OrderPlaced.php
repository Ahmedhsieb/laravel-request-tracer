<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Events;

use RequestTracer\Tests\Fixtures\Models\Order;

class OrderPlaced
{
    public function __construct(public readonly Order $order) {}
}
