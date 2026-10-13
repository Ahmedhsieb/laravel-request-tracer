<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests;

use Illuminate\Contracts\Foundation\Application;

/** The package booted in the `local` environment: open dashboard, recording on by default. */
abstract class LocalTestCase extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.env', 'local');
        $app['config']->set('request-tracer.mode', null);          // let the environment decide
        $app->detectEnvironment(fn (): string => 'local');
    }
}
