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

> *Screenshot placeholder:* `docs/images/dashboard-flow.png`, see [docs/images](docs/images/README.md).

## Two steps

```bash
composer require your-vendor/laravel-request-tracer

php artisan request-tracer:listen
```

That is all: no config file, no migration, nothing to publish. Use your application as usual and open `/request-tracer`.

`request-tracer:listen` works like `queue:work`. **While it runs**, everything the project does is traced: requests, queued jobs, artisan commands, scheduled tasks. Each one is printed in the terminal as it is stored. Press Ctrl+C and recording stops within a few seconds. **When it is not running, nothing is recorded**, and the package costs 25 to 65 µs per request.

### Which code is traced?

**All of `app/`**, every call with its input and output and, line by line, every condition, block and variable. You do not list classes. To look at another folder, or a single file, or to leave something out:

```php
// config/request-tracer.php   (php artisan vendor:publish --tag=request-tracer-config)
'code' => [
    'paths' => ['app', 'modules/Billing/src', 'lib/Money.php'],   // folders or files, relative to the project
    'except' => ['app/Legacy', 'App\Reports\*'],                   // leave these out; nothing else is
],
```

### A request that already happened

Nothing was recording when it ran? **Run it again, traced.** In the dashboard: *Replay a request*, then paste the cURL command (browser: Network tab, *Copy as cURL*), or press *Replay this request* on a trace you already have. From the terminal:

```bash
php artisan request-tracer:replay "POST /orders" --data='{"items":[1,2]}' --as=42
```

The application handles it as if it had arrived from outside, and **its database changes are rolled back**, e-mails and queued jobs are not sent. Calls to other services (payments, APIs) are real, so replay is off in the production environment unless you switch it on.

### Do not know what the request was?

Turn the **request journal** on (`REQUEST_TRACER_JOURNAL=true`). It notes every request in one light row (URL, masked body, user, status, error), traced or not, for an hour. In the dashboard's *Journal* tab pick the one that failed and it **replays itself**: same URL, same body, same user, full trace. `php artisan request-tracer:replay --journal=failed` does it from the terminal. It is off by default, because it keeps request bodies for that hour; secrets are masked before they are stored.

### In production

Same two steps, on the server. The dashboard stays closed until you say who may open it:

```php
Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

Secrets are masked before they are stored, a failure inside the tracer never breaks a request, and a request can also be traced without the command: [signed header, watched user, sampling](docs/07-running-in-production.md).

## Requirements

PHP 8.1+, Laravel 10, 11, 12 or 13, MySQL / MariaDB, PostgreSQL or SQLite for the traces (the tables create themselves). Works with Octane and queue workers.

Want to look around first? `npm ci && npm run dev` opens the dashboard with demo data and no backend.

## Documentation

| | |
|---|---|
| [1. Introduction](docs/01-introduction.md) | What it is and is not |
| [2. Installation](docs/02-installation.md) | Requirements, what happens on install |
| [3. Quick start](docs/03-quick-start.md) | Ten minutes to a useful trace |
| [4. Configuration](docs/04-configuration.md) | Every key |
| [5. How capture works](docs/05-how-capture-works.md) | What is seen and what is not |
| [6. Deep trace](docs/06-deep-trace-guide.md) | Line by line |
| [7. Running in production](docs/07-running-in-production.md) | Listening, triggers, masking, retention |
| [8. Large applications and ERPs](docs/08-large-applications.md) | Modules, tenants, limits |
| [9. Using the dashboard](docs/09-using-the-dashboard.md) | A tour, replay |
| [10. Jobs, commands, scheduled tasks](docs/10-queues-commands-scheduled-tasks.md) | Beyond requests |
| [11. Extending](docs/11-extending.md) | Collectors, storage, masking, resolvers |
| [12. How it compares](docs/12-comparison.md) | Telescope, Debugbar, Clockwork |
| [13. Troubleshooting, FAQ, upgrading, contributing](docs/13-troubleshooting-faq-upgrade.md) | |
| [Benchmarks](docs/benchmarks.md), [JSON API](docs/api.md), [Decisions](docs/decisions.md), [Security](SECURITY.md), [Changelog](CHANGELOG.md) | |

## What it costs

Nothing you can measure when nobody listens (25 to 65 µs per request). While listening, a request of 47 steps and 15 queries takes about 5 ms longer with input and output for every call of your code, about 7.5 ms with every line as well, plus 7 to 10 ms after the response was sent: measured, with the method and the caveats, in [docs/benchmarks.md](docs/benchmarks.md).

## Honest limits

Proxies cannot see `new`, final classes, private or static methods. Line-by-line tracing compiles a copy of each file of `code.paths` the first time it is loaded while somebody listens (PHP-FPM and `artisan serve` load classes on every request, so they just work; a long-running worker such as Octane or `queue:work` keeps the classes it loaded before you started listening, as they were, until it restarts). Masking is name based. The package is `0.x`. The full list is in [How it compares](docs/12-comparison.md#what-request-tracer-costs-you).

## License

MIT. See [LICENSE](LICENSE). العربية: [README.ar.md](README.ar.md)
