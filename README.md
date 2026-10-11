# Request Tracer

[![Latest version](https://img.shields.io/packagist/v/your-vendor/laravel-request-tracer.svg)](https://packagist.org/packages/your-vendor/laravel-request-tracer)
[![Tests](https://github.com/your-vendor/laravel-request-tracer/actions/workflows/ci.yml/badge.svg)](https://github.com/your-vendor/laravel-request-tracer/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/php-8.1%20%E2%80%93%208.4-777bb4.svg)](docs/02-installation.md)
[![Laravel](https://img.shields.io/badge/laravel-10%20%E2%80%93%2013-ff2d20.svg)](docs/02-installation.md)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Replace `dd()` with a trace of the whole request.**

When a request misbehaves, `dd()` shows you one value in one place and kills the request. Request Tracer records the **whole request** as a tree and lets you look inside every step:

- **Flow**: middleware, controller, validation, services, queries, HTTP calls, events, jobs, exceptions, with time bars.
- **Input / Output** of each step, printed the way `dd()` prints it.
- **Inside the code**: line by line, which conditions were true or false, which blocks ran, what the variables held. Original line numbers, and a *Replay* button.
- **Database**: every table the request wrote, with the row before and after.
- **Problems**: caught exceptions, N+1 queries, repeated and slow queries, slow steps, failed HTTP calls.

It is built to be left installed in production: **nothing is recorded unless you ask** (a signed header, a watched user, a small sample), secrets are masked before they are stored, the dashboard is closed until you open a gate, and a failure inside the tracer never breaks a request.

> *Screenshot placeholder:* `docs/images/dashboard-flow.png`, see [docs/images](docs/images/README.md).

## Quick start

```bash
# 1. install
composer require your-vendor/laravel-request-tracer

# 2. publish the config, migrations and dashboard, and migrate
php artisan request-tracer:install --migrate

# 3. use your app, then open the dashboard (local environment: no login needed)
open http://localhost:8000/request-tracer
```

To see **your own services** in the trace, list their namespaces in `config/request-tracer.php`:

```php
'proxies' => ['namespaces' => ['App\Services', 'App\Domain\*']],
'deep_trace' => ['namespaces' => ['App\Services\OrderService']],   // line by line, for this class
```

In production, define who may open the dashboard and record one request with a signed header:

```php
Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

```bash
curl -H "X-Request-Tracer: $(php artisan request-tracer:token --ttl=300 | head -1)" https://shop.example.com/orders
```

Or try the dashboard first with demo data and no backend: `npm ci && npm run dev`.

## Requirements

PHP 8.1+, Laravel 10, 11, 12 or 13, MySQL / MariaDB, PostgreSQL or SQLite for the traces. Works with Octane and queue workers.

## Documentation

| | |
|---|---|
| [1. Introduction](docs/01-introduction.md) | What it is and is not |
| [2. Installation](docs/02-installation.md) | Requirements, publish, gate |
| [3. Quick start](docs/03-quick-start.md) | Ten minutes to a useful trace |
| [4. Configuration](docs/04-configuration.md) | Every key |
| [5. How capture works](docs/05-how-capture-works.md) | The four layers, what is and is not seen |
| [6. Deep trace](docs/06-deep-trace-guide.md) | Line by line |
| [7. Running in production](docs/07-running-in-production.md) | Triggers, masking, retention |
| [8. Large applications and ERPs](docs/08-large-applications.md) | Modules, tenants, limits |
| [9. Using the dashboard](docs/09-using-the-dashboard.md) | A tour |
| [10. Jobs, commands, scheduled tasks](docs/10-queues-commands-scheduled-tasks.md) | Beyond requests |
| [11. Extending](docs/11-extending.md) | Collectors, storage, masking, resolvers |
| [12. How it compares](docs/12-comparison.md) | Telescope, Debugbar, Clockwork |
| [13. Troubleshooting, FAQ, upgrading, contributing](docs/13-troubleshooting-faq-upgrade.md) | |
| [Benchmarks](docs/benchmarks.md), [JSON API](docs/api.md), [Decisions](docs/decisions.md), [Security](SECURITY.md), [Changelog](CHANGELOG.md) | |

## What it costs

About 25 µs on a request nobody asked it to record; roughly 5 to 7 ms added to a recorded request of 47 steps and 15 queries, plus 7 to 10 ms after the response was sent. Measured, with the method and the caveats, in [docs/benchmarks.md](docs/benchmarks.md).

## Honest limits

Proxies cannot see `new`, final classes, private or static methods. Deep trace compiles a copy of the classes you name and only affects classes loaded after it starts. Masking is name based. The package is `0.x`. The full list is in [How it compares](docs/12-comparison.md#what-request-tracer-costs-you).

## License

MIT. See [LICENSE](LICENSE). العربية: [README.ar.md](README.ar.md)
