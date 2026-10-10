<?php

declare(strict_types=1);

namespace RequestTracer\Tests;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use Orchestra\Testbench\TestCase as Orchestra;
use RequestTracer\RequestTracerServiceProvider;
use RequestTracer\Storage\ConnectionResolver;

abstract class TestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [RequestTracerServiceProvider::class];
    }

    /**
     * The suite runs on in-memory SQLite by default. CI (and you) can point it at a real server:
     * TRACER_TEST_DB=sqlite|mysql|pgsql  plus TRACER_TEST_DB_{HOST,PORT,USERNAME,PASSWORD,DATABASE}.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('app.env', 'testing');

        $db = getenv('TRACER_TEST_DB') ?: 'memory';
        if ($db === 'sqlite' && ! file_exists($file = sys_get_temp_dir().'/request-tracer-tests.sqlite')) {
            touch($file);
        }
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', match ($db) {
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('TRACER_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('TRACER_TEST_DB_PORT') ?: '3306',
                'database' => getenv('TRACER_TEST_DB_DATABASE') ?: 'tracer_test',
                'username' => getenv('TRACER_TEST_DB_USERNAME') ?: 'root',
                'password' => getenv('TRACER_TEST_DB_PASSWORD') ?: '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => getenv('TRACER_TEST_DB_HOST') ?: '127.0.0.1',
                'port' => getenv('TRACER_TEST_DB_PORT') ?: '5432',
                'database' => getenv('TRACER_TEST_DB_DATABASE') ?: 'tracer_test',
                'username' => getenv('TRACER_TEST_DB_USERNAME') ?: 'postgres',
                'password' => getenv('TRACER_TEST_DB_PASSWORD') ?: 'postgres',
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => sys_get_temp_dir().'/request-tracer-tests.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
            default => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ],
        });

        $app['config']->set('request-tracer.enabled', true);
        $app['config']->set('request-tracer.mode', 'always');
        $app['config']->set('request-tracer.storage.separate_connection', true);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('logging.default', 'null');
        $app['config']->set('logging.channels.request-tracer', ['driver' => 'monolog', 'handler' => NullHandler::class]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Real database servers keep rows between tests; start every test with empty tracer tables.
        $this->truncateTracerTables();
    }

    protected function truncateTracerTables(): void
    {
        $db = $this->app->make(ConnectionResolver::class)->resolve();
        foreach (['problems', 'writes', 'lines', 'spans', 'traces'] as $table) {
            $db->table('tracer_'.$table)->delete();
        }
    }

    /** @return Builder */
    protected function tracerTable(string $name)
    {
        return $this->app->make(ConnectionResolver::class)->resolve()->table('tracer_'.$name);
    }

    protected function applicationDb(): Connection
    {
        return DB::connection('testing');
    }
}
