<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Listeners;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Events\OrderPlaced;
use Illuminate\Support\Facades\Log;

class SendOrderConfirmation
{
    public function handle(OrderPlaced $event): void
    {
        Log::info('Order confirmation sent', ['order_id' => $event->order->id, 'email_token' => 'tok_should_be_masked']);
    }
}
