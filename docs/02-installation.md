# 2. Installation

## Requirements

| | Supported |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 (the package uses PHP 8.1 syntax only; the CI matrix also runs 8.5) |
| Laravel | 10, 11, 12, 13 |
| Database for the traces | MySQL / MariaDB, PostgreSQL, SQLite |
| Extensions | `json` (required); `zlib` makes stored payloads smaller |
| Other packages | `nikic/php-parser` 4.18+ or 5.x (installed automatically; used by Deep trace) |

Compatibility is checked in CI on every Laravel version with both the lowest and the highest allowed dependencies. SQL Server is understood by the SQL analyser (table names in queries) and covered by unit tests, but the storage tables are only tested on the three databases above.

## 1. Install the package

```bash
composer require your-vendor/laravel-request-tracer
```

The service provider is discovered automatically.

Install it as a normal dependency, not `--dev`, if you want to use it in production. You can also keep it in `require-dev` and use it only locally and on staging.

## 2. Publish and migrate

One command does the setup:

```bash
php artisan request-tracer:install --migrate
```

It publishes the config file, the migrations and the dashboard assets, and runs `php artisan migrate`. The same thing by hand:

```bash
php artisan vendor:publish --tag=request-tracer-config       # config/request-tracer.php
php artisan vendor:publish --tag=request-tracer-migrations   # database/migrations/…create_request_tracer_tables.php
php artisan vendor:publish --tag=request-tracer-assets       # public/vendor/request-tracer (optional)
php artisan migrate
```

Publishing the assets is optional: the dashboard is served from inside the package. A published copy takes precedence, which lets you pin or patch it, and lets a CDN or web server serve the files directly.

The migration creates five tables (`tracer_traces`, `tracer_spans`, `tracer_lines`, `tracer_writes`, `tracer_problems`). To keep them out of your main database, set `REQUEST_TRACER_DB_CONNECTION=tracer` to a connection you define in `config/database.php` and run the migration on that connection.

## 3. Open the dashboard

In the `local` environment, open `/request-tracer`. Nothing else is needed.

In any other environment the dashboard answers `403` until you decide who may see it. A trace contains your SQL and request data, so keep this list short:

```php
// app/Providers/AppServiceProvider.php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
}
```

## 4. Record something

- **Local:** just use your application. Every request is recorded and appears in the list within a few seconds.
- **Staging / production:** nothing is recorded until you ask. In the dashboard click **Record a request**, copy the signed header, and send one request with it. See the [Quick start](03-quick-start.md).

## Upgrading, uninstalling

- Upgrading: `composer update your-vendor/laravel-request-tracer`, then `php artisan vendor:publish --tag=request-tracer-assets --force`, and check [the upgrade notes](13-troubleshooting-faq-upgrade.md#upgrading). New releases that need a schema change ship a new migration.
- Switching it off without removing it: `REQUEST_TRACER_ENABLED=false`.
- Uninstalling: `composer remove your-vendor/laravel-request-tracer`, delete `config/request-tracer.php`, `public/vendor/request-tracer` and the published migration, and drop the five `tracer_*` tables. Generated code lives in `storage/framework/request-tracer` and can be deleted too.

## Laravel Octane, Horizon, queue workers

Nothing to configure. The package keeps no per-request state in the application instance, so it works with Octane and long-running workers. After changing `config/request-tracer.php`, restart the workers as you would for any other config change.
