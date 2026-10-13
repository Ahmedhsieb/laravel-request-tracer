<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Services;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Models\Product;

class InventoryService
{
    public function reserve(Product $product, int $qty): void
    {
        $product->update(['stock' => $product->stock - $qty]);
    }
}
