<?php

declare(strict_types=1);

namespace RequestTracer\Support;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Resolves the *current* application on demand.
 *
 * Long-lived workers (Octane) clone the application for every request and point `Container::getInstance()` at the clone.
 * Singletons that keep a reference to the application they were created with would keep reading the first request's
 * config and request, so the package never stores the application; it asks here instead.
 */
final class App
{
    public static function container(): Container
    {
        return Container::getInstance();
    }

    public static function config(): Repository
    {
        /** @var Repository $config */
        $config = self::container()->make('config');

        return $config;
    }

    public static function application(): Application
    {
        /** @var Application $app */
        $app = self::container()->make(Application::class);

        return $app;
    }
}
