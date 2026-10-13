<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Events;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Order;

class OrderPlaced
{
    public function __construct(public readonly Order $order) {}
}
