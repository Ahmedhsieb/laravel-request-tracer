<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Contracts;

interface CurrencyProvider
{
    public function code(): string;
}
