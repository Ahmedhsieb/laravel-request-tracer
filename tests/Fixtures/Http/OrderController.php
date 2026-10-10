<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Http;

use Illuminate\Http\JsonResponse;
use RequestTracer\Tests\Fixtures\Services\OrderService;

class OrderController
{
    public function store(StoreOrderRequest $request, OrderService $orders): JsonResponse
    {
        $order = $orders->create($request->user(), $request->validated());

        return response()->json(['id' => $order->id, 'status' => $order->status, 'total' => $order->total], 201);
    }
}
