<?php

declare(strict_types=1);

namespace RequestTracer\Facades;

use Illuminate\Support\Facades\Facade;
use RequestTracer\Support\Brand;

/**
 * @mixin \RequestTracer\Tracer
 *
 * @method static bool enabled()
 * @method static bool recording()
 */
final class Tracer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Brand::CONTAINER_KEY;
    }
}
