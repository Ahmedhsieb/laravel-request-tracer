<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Providers;

use RequestTracer\Tests\Fixtures\Contracts\CurrencyProvider;

class DollarProvider implements CurrencyProvider
{
    public function code(): string
    {
        return 'USD';
    }
}
