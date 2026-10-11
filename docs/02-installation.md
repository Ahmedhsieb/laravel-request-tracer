# 2. Installation

## Requirements

| | Supported |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 (the package uses PHP 8.1 syntax only; the CI matrix also runs 8.5) |
| Laravel | 10, 11, 12, 13 |
| Database for the traces | MySQL / MariaDB, PostgreSQL, SQLite |
| Extensions | `json` (required); `zlib` makes stored payloads smaller |
| Other packages | `nikic/php-parser` 4.18+ or 5.x (installed automatically; used by line-by-line tracing) |

Compatibility is checked in CI on every Laravel version with both the lowest and the highest allowed dependencies. SQL Server is understood by the SQL analyser (table names in queries) and covered by unit tests, but the storage tables are only tested on the three databases above.

## Install

```bash
composer require your-vendor/laravel-request-tracer
```

That is the whole installation. The service provider is discovered automatically, and there is **nothing to publish or migrate**:

- **The tables** (`tracer_traces`, `tracer_spans`, `tracer_lines`, `tracer_writes`, `tracer_problems`) are created the first time they are needed. If you would rather have them in a migration (`php artisan migrate` on deploy), the package already ships it; that works too, and the two never clash.
- **The dashboard** is served from inside the package.
- **The configuration** has defaults for everything. A config file is only needed to change something, and only the keys you change need to be in it (`php artisan vendor:publish --tag=request-tracer-config`).

Install it as a normal dependency, not `--dev`, if you want to use it in production. You can also keep it in `require-dev` and use it only locally and on staging.

## Use

```bash
php artisan request-tracer:listen
```

Keep it running while you use the application, and open `/request-tracer` in the browser. See the [quick start](03-quick-start.md).

In the `local` environment the dashboard opens for everybody. In any other environment it answers `403` until you decide who may see it; a trace contains your SQL and request data, so keep this list short:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
}
```

## Where the traces are stored

In your application's default database connection. To keep them elsewhere, set `REQUEST_TRACER_DB_CONNECTION=tracer` to a connection you define in `config/database.php`. Traces are written on a separate PDO connection by default, so they never take part in your transactions.

## Optional extras

```bash
php artisan vendor:publish --tag=request-tracer-config       # config/request-tracer.php, to change what is traced
php artisan vendor:publish --tag=request-tracer-assets       # public/vendor/request-tracer, to serve the dashboard files from the web server
```

`php artisan request-tracer:install` does the same (publishes the config and the assets) in one go; you can skip it.

## Upgrading, uninstalling

- Upgrading: `composer update your-vendor/laravel-request-tracer`, and read [the upgrade notes](13-troubleshooting-faq-upgrade.md#upgrading). If you published the assets, publish them again with `--force`.
- Switching it off without removing it: `REQUEST_TRACER_ENABLED=false` (the package then registers nothing).
- Uninstalling: `composer remove your-vendor/laravel-request-tracer`, delete `config/request-tracer.php` and `public/vendor/request-tracer` if you published them, and drop the five `tracer_*` tables. Generated code lives in `storage/framework/request-tracer` and can be deleted too.

## Laravel Octane, Horizon, queue workers

Nothing to configure. The package keeps no per-request state in the application instance, so it works with Octane and long-running workers. A worker that was already running when you started `request-tracer:listen` keeps the classes it had loaded as they were (they are not compiled for line-by-line tracing) until it restarts; its calls are still traced.
