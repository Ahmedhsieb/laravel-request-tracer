<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Storage;

use Ahmedhsieb\RequestTracer\Support\App;
use Ahmedhsieb\RequestTracer\Support\Failure;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the five tables when they are missing, so installing the package is `composer require` and nothing else.
 * It runs the package's own migration file (which does nothing when the tables exist), so `php artisan migrate` and this give the same result.
 */
final class TableInstaller
{
    private static bool $attempted = false;

    private static bool $journalAttempted = false;

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

    /** The request journal's table, created the same way (only needed when `journal.enabled` is on or the journal is read). */
    public static function ensureJournal(): bool
    {
        return Failure::guard(static function (): bool {
            $connection = App::config()->get('request-tracer.storage.connection');
            $schema = Schema::connection(is_string($connection) && $connection !== '' ? $connection : null);
            $table = (string) App::config()->get('request-tracer.storage.table_prefix', 'tracer_').'journal';
            if ($schema->hasTable($table)) {
                return true;
            }
            if (self::$journalAttempted) {
                return false;
            }
            self::$journalAttempted = true;

            $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_11_000000_create_request_tracer_journal_table.php';
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }

            return $schema->hasTable($table);
        }, 'creating the journal table', false);
    }

    /** Forget that an attempt was made (tests). */
    public static function reset(): void
    {
        self::$attempted = false;
        self::$journalAttempted = false;
    }
}
