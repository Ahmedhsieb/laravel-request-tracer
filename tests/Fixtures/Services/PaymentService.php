<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Services;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Order;
use Illuminate\Support\Facades\Http;

class PaymentService
{
    /**
     * @return array{status: string, id?: string}
     */
    public function charge(Order $order, string $method, ?string $cardNumber): array
    {
        $response = Http::withToken('sk_live_super_secret_key')
            ->acceptJson()
            ->post('https://payments.test/v1/charges?api_key=query_secret_123', [
                'order_id' => $order->id,
                'amount' => $order->total,
                'method' => $method,
                'card_number' => $cardNumber,
            ]);

        return $response->json() ?? ['status' => 'error'];
    }
}
