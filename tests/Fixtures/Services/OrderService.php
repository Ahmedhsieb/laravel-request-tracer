<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RequestTracer\Tests\Fixtures\Events\OrderPlaced;
use RequestTracer\Tests\Fixtures\Exceptions\CouponException;
use RequestTracer\Tests\Fixtures\Exceptions\OutOfStockException;
use RequestTracer\Tests\Fixtures\Models\Order;
use RequestTracer\Tests\Fixtures\Models\OrderItem;
use RequestTracer\Tests\Fixtures\Models\Product;
use RequestTracer\Tests\Fixtures\Models\User;

class OrderService
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CouponService $coupons,
        private readonly InventoryService $inventory,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Order
    {
        $lines = $data['items'];
        $subtotal = $this->pricing->subtotal($lines);

        $discount = 0.0;
        $couponNote = null;
        if (! empty($data['coupon'])) {
            try {
                $discount = $this->coupons->apply($data['coupon'], $subtotal);
            } catch (CouponException $e) {
                $couponNote = $e->getMessage();
            }
        } elseif ($subtotal > 500) {
            $discount = $this->pricing->bulkDiscount($subtotal);
        } else {
            $discount = 0.0;
        }

        $total = $user->is_vip ? $subtotal - $discount - $this->pricing->vipDiscount($subtotal) : $subtotal - $discount;

        if ($total <= 0 || count($lines) === 0) {
            throw new InvalidArgumentException('Order total must be positive.');
        }

        $order = DB::transaction(function () use ($user, $lines, $total, $couponNote): Order {
            $order = Order::create(['user_id' => $user->id, 'total' => $total, 'status' => 'pending', 'note' => $couponNote]);

            foreach ($lines as $line) {
                $product = Product::query()->findOrFail($line['product_id']);
                if ($product->stock < $line['qty']) {
                    throw new OutOfStockException("Not enough stock for {$product->sku}");
                }
                $this->inventory->reserve($product, $line['qty']);
                OrderItem::create(['order_id' => $order->id, 'product_id' => $product->id, 'qty' => $line['qty'], 'price' => $product->price]);
            }

            return $order;
        });

        $payment = $this->payments->charge($order, $data['payment_method'] ?? 'card', $data['card_number'] ?? null);
        $status = match ($payment['status']) {
            'approved' => 'paid',
            'declined' => 'declined',
            default => 'pending',
        };
        $order->update(['status' => $status]);

        event(new OrderPlaced($order));

        return $order;
    }
}
