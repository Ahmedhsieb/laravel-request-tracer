<?php

declare(strict_types=1);

namespace RequestTracer;

use Illuminate\Support\ServiceProvider;
use RequestTracer\Support\Brand;

final class RequestTracerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/'.Brand::NAME.'.php', Brand::NAME);

        $this->app->singleton(Tracer::class, fn ($app): Tracer => new Tracer($app['config']->get(Brand::NAME, [])));
        $this->app->alias(Tracer::class, Brand::CONTAINER_KEY);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/'.Brand::NAME.'.php' => config_path(Brand::NAME.'.php')], Brand::NAME.'-config');
        }
    }
}
