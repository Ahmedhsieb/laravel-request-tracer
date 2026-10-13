<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Services;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Contracts\CurrencyProvider;

class RateService
{
    public function __construct(private readonly CurrencyProvider $currency) {}

    public function currency(): string
    {
        return $this->currency->code();
    }
}
