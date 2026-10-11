<?php

declare(strict_types=1);

namespace RequestTracer\Storage;

use Illuminate\Support\Facades\Schema;
use RequestTracer\Support\App;
use RequestTracer\Support\Failure;

/**
 * Creates the five tables when they are missing, so installing the package is `composer require` and nothing else.
 * It runs the package's own migration file (which does nothing when the tables exist), so `php artisan migrate` and this give the same result.
 */
final class TableInstaller
{
    private static bool $attempted = false;

    /** True when the tables exist afterwards. One attempt per process; a failure is logged, never thrown. */
    public static function ensure(): bool
    {
        return Failure::guard(static function (): bool {
            $connection = App::config()->get('request-tracer.storage.connection');
            $schema = Schema::connection(is_string($connection) && $connection !== '' ? $connection : null);
            $traces = (string) App::config()->get('request-tracer.storage.table_prefix', 'tracer_').'traces';
            if ($schema->hasTable($traces)) {
                return true;
            }
            if (self::$attempted) {
                return false;
            }
            self::$attempted = true;

            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_01_000000_create_request_tracer_tables.php';
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }

            return $schema->hasTable($traces);
        }, 'creating the tracer tables', false);
    }

    /** Forget that an attempt was made (tests). */
    public static function reset(): void
    {
        self::$attempted = false;
    }
}
