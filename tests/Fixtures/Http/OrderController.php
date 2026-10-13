<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Http;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController
{
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $order = $orders->create($request->user(), $request->validated());

        return response()->json(['id' => $order->id, 'status' => $order->status, 'total' => $order->total], 201);
    }
}
