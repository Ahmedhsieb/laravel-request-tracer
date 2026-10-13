<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Services;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Exceptions\CouponException;

class CouponService
{
    public function apply(string $code, float $subtotal): float
    {
        $coupons = ['SAVE10' => 0.10, 'OLD2020' => null];

        if (! array_key_exists($code, $coupons)) {
            throw new CouponException("Coupon {$code} does not exist");
        }
        if ($coupons[$code] === null) {
            throw new CouponException("Coupon {$code} has expired");
        }

        return round($subtotal * $coupons[$code], 2);
    }
}
