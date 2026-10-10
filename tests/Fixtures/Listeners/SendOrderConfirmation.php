<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Listeners;

use Illuminate\Support\Facades\Log;
use RequestTracer\Tests\Fixtures\Events\OrderPlaced;

class SendOrderConfirmation
{
    public function handle(OrderPlaced $event): void
    {
        Log::info('Order confirmation sent', ['order_id' => $event->order->id, 'email_token' => 'tok_should_be_masked']);
    }
}
