<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Services;

use RequestTracer\Tests\Fixtures\Models\Product;

class PricingService
{
    /**
     * @param  list<array{product_id: int, qty: int}>  $lines
     */
    public function subtotal(array $lines): float
    {
        $products = Product::query()->whereIn('id', array_column($lines, 'product_id'))->get()->keyBy('id');

        $sum = 0.0;
        foreach ($lines as $line) {
            $sum += (float) $products[$line['product_id']]->price * $line['qty'];
        }

        return round($sum, 2);
    }

    public function bulkDiscount(float $subtotal): float
    {
        return round($subtotal * 0.05, 2);
    }

    public function vipDiscount(float $subtotal): float
    {
        return round($subtotal * 0.1, 2);
    }
}
