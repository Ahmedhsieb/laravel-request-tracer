<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Providers;

use Ahmedhsieb\RequestTracer\Tests\Fixtures\Contracts\CurrencyProvider;

class DollarProvider implements CurrencyProvider
{
    public function code(): string
    {
        return 'USD';
    }
}
