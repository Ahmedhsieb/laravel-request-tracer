<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Facades;

use Ahmedhsieb\RequestTracer\Support\Brand;
use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Ahmedhsieb\RequestTracer\Tracer
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
