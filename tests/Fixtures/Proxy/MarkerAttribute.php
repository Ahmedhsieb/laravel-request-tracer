<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Proxy;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class MarkerAttribute
{
    public function __construct(public readonly string $name = '', public readonly bool $flag = false, public readonly array $list = []) {}
}
