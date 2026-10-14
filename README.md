# Request Tracer

![Latest version](https://img.shields.io/packagist/v/ahmedhsieb/laravel-request-tracer.svg)
![Tests](https://github.com/ahmedhsieb/laravel-request-tracer/actions/workflows/ci.yml/badge.svg)
![PHP](https://img.shields.io/badge/php-8.1%20%E2%80%93%208.4-777bb4.svg)
![Laravel](https://img.shields.io/badge/laravel-10%20%E2%80%93%2013-ff2d20.svg)
![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)

**Replace** `dd()` **with a trace of the whole request.**

`dd()` shows one value in one place and kills the request. Request Tracer records the **whole request**, step by step, and lets you open any step: what went in, what came out, which lines ran, which rows were written.

![The dashboard: a request with its steps on a timeline, the details of the pinned step on the right](docs/images/dashboard-flow.jpg)

- [Install](#install) · [Start tracing](#start-tracing) · [Features](#features) · [Configure](#configure) · [Commands](#commands) · [Examples](#examples) · [Production](#in-production)

---



## Install

```bash
composer require ahmedhsieb/laravel-request-tracer
```

That is all. No config file, no migration, nothing to publish: the tables create themselves and the dashboard is served from the package.


| Needs    |                                                        |
| -------- | ------------------------------------------------------ |
| PHP      | 8.1 – 8.4                                              |
| Laravel  | 10, 11, 12, 13                                         |
| Database | MySQL / MariaDB, PostgreSQL or SQLite (for the traces) |


Want to look around first? `npm ci && npm run dev` in the package folder opens the dashboard with demo data, no backend needed.

## Start tracing

```bash
php artisan request-tracer:listen
```

Use your application as usual, then open `/request-tracer`.

It works like `queue:work`: **while it runs**, everything the project does is traced (requests, queued jobs, artisan commands, scheduled tasks) and each trace is printed in the terminal. Press Ctrl+C and it stops. **When it is not running nothing is recorded**, and the package costs 25 to 65 µs per request.

- **Local:** the dashboard is open to everyone.
- **Any other environment:** closed (`403`) until you define who may open it, see [Production](#in-production).

---



## Features



### The flow: everything the request did

Middleware, controller, validation, services, queries, HTTP calls, events, jobs, cache, logs and exceptions, each on a **timeline** with its real start and duration. Above it: time, memory, queries, problems, tables written, and **where the time went** by kind of step. The strip under the tabs is a **time map**: drag over it to zoom the timeline into that part of the request.

Hover a step to preview it, click to pin it. The right side shows its **Input and Output**, printed like `dd()`, with secrets masked.

### Folders: deep requests stay readable

A step with steps inside is a **folder**. The list shows one folder at a time, so names are never squeezed by indentation, however deep the request goes. Open a folder with the button on its row, go **up** with the arrow, or jump to any level from the **path** above the list. The time axis follows: an open folder shows its own stretch of time.

![A folder opened: its steps, the path above the list, and the time axis of the folder](docs/images/folders.jpg)

### Search and filters, inside the folder

Search by name, SQL, file or summary. Filter by kind (query, HTTP, cache...), speed (slow / fast) and result (failed / OK). They look at **everything below the open folder**, however deep, and list the matches with the path each one is in. A switch narrows them to the folder's own steps.

![Searching "select": every match below the open folder, each with its path](docs/images/filters.jpg)

### Graph view

The same steps as **columns that grow to the right**. Click a step and what is inside it opens in the next column; the path you came by stays on the left, and clicking an earlier step goes back. Lines are as thick as the share of the time a step took.

![The graph: the path on the left, the steps of a level, and what is inside the chosen step](docs/images/graph.jpg)

### Inside the code, line by line

Every condition, block and variable of a step: which `if` was true, which block never ran, what the variables held. **Hover a line** to see what happened there, **click it** to keep the pop-up open. **Replay** walks through the lines in the order they ran. Functions the step called appear under the line that calls them.

![The code of a step: green lines ran, a purple one was false, and a pop-up shows the condition](docs/images/code.jpg)


| Dot    | Meaning                      |
| ------ | ---------------------------- |
| green  | the line ran                 |
| purple | a condition was `false`      |
| red    | the line threw               |
| orange | an exception was caught here |
| blue   | a loop (with its iterations) |




### Database: what the request wrote

Every table the request changed: `INSERT`, `UPDATE`, `DELETE`, the rows, and for updates **before → after** with the changed cells highlighted. Each card names the step that wrote it and whether the transaction committed or rolled back.

![The Database tab: inserted rows, and an update with before and after values](docs/images/database.jpg)

### Problems: found for you

Uncaught and caught exceptions, **N+1 queries**, repeated and slow queries, slow steps and failed HTTP calls, each with an explanation, the file and line, and a link to the step in the flow.

![The Problems tab](docs/images/problems.jpg)

### Replay: run a request again, traced

Nothing was recording when it happened? Run it again. In the dashboard press **Replay a request**, paste a cURL command (browser: Network tab, *Copy as cURL*) or describe the request. You choose what it may change:


|                                                 | Simulation (default)         | Run for real                            |
| ----------------------------------------------- | ---------------------------- | --------------------------------------- |
| Database                                        | rolled back, nothing changes | changes are kept                        |
| E-mails, notifications, jobs, HTTP-client calls | do not go out                | go out                                  |
| Afterwards                                      |                              | **Undo** puts every Eloquent write back |


![The replay dialog: simulation or run for real](docs/images/replay.jpg)

Replay is off in the `production` environment unless you turn it on; running for real there needs a second switch.

### Journal: when you do not know which request it was

Turn it on (`REQUEST_TRACER_JOURNAL=true`) and every request leaves one light row (URL, masked body, user, status, error), traced or not, for an hour. Pick the one that failed and it **replays itself** with a full trace. **Group by route** puts the requests of one route prefix together in the list and in the journal.

![The journal: every request, the failed ones in red, and a Trace button](docs/images/journal.jpg)

![Requests grouped by route](docs/images/group.jpg)

### Also in the dashboard

- **Ctrl+K**: search requests, the steps of the open request, and actions, from the keyboard.
- **Record from outside**: a signed header for one request, or watch a user for a while, for servers where you cannot run the listen command.
- **Jobs and commands**: a job started by a request links back to it, and the other way round.
- **Dark and light** themes; panes you can resize; works on a narrow screen.

![The command palette](docs/images/palette.jpg)

![Record from outside: a signed header, or watch a user](docs/images/record.jpg)

![The light theme](docs/images/light.jpg)

---



## Configure

There is nothing to configure to start. To change something publish the file and keep only the keys you change (every key has a default):

```bash
php artisan vendor:publish --tag=request-tracer-config
```

The keys you will reach for first:


| Key                                     | Default                                  | What it does                                                                                                       |
| --------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `code.paths`                            | `['app']`                                | Folders or files whose code is traced, line by line. Everything inside is traced, you never list classes.          |
| `code.except`                           | `[]`                                     | Folders, files or classes to leave out (`'app/Legacy'`, `'App\Reports\*'`). `#[NoTrace]` on a class does the same. |
| `dashboard.path`                        | `request-tracer`                         | URL of the dashboard.                                                                                              |
| `dashboard.gate`                        | `viewRequestTracer`                      | The gate that decides who may open it.                                                                             |
| `journal.enabled`                       | `false`                                  | Note every request so it can be replayed (`REQUEST_TRACER_JOURNAL`).                                               |
| `replay.enabled`, `replay.real`         | `null`                                   | Allow replay (and running for real). `null` = everywhere except production.                                        |
| `masking.keys`                          | `password`, `token`, `secret`, `card`... | Field names whose values are masked **before** anything is stored. Yours are added.                                |
| `exclude.paths`                         | login, webhooks, health checks...        | URLs that are never recorded. Yours are added.                                                                     |
| `retention.hours`, `retention.max_rows` | `24`, `5000`                             | How long traces are kept (cleaned up automatically).                                                               |
| `limits.max_spans`                      | `5000`                                   | Steps stored per trace.                                                                                            |
| `unlimited`                             | `false`                                  | Store everything, whatever its size, so you never tune the numbers above. Only the process running out of memory stops it. For debugging (`REQUEST_TRACER_UNLIMITED=true`). |
| `storage.connection`                    | app default                              | Database connection that holds the tables (`REQUEST_TRACER_DB_CONNECTION`).                                        |


Environment variables: `REQUEST_TRACER_ENABLED`, `REQUEST_TRACER_MODE`, `REQUEST_TRACER_JOURNAL`, `REQUEST_TRACER_DB_CONNECTION`. Every key is in [docs/04-configuration.md](docs/04-configuration.md).

```php
// config/request-tracer.php
return [
    'code' => [
        'paths'  => ['app', 'modules/Billing/src', 'lib/Money.php'],
        'except' => ['app/Legacy', 'App\Reports\*'],
    ],
    'masking' => ['keys' => ['ssn', 'national_id']],
    'journal' => ['enabled' => true],
];
```

---



## Commands


| Command                                            | What it does                                                                                                             |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| `php artisan request-tracer:listen`                | Trace everything while it runs. `--seconds=60` stops after a minute, `--no-feed` prints nothing.                         |
| `php artisan request-tracer:replay "POST /orders"` | Run a request again, traced. Options: `--data=`, `--header=`, `--as=<user id>`, `--curl=`, `--journal=failed`, `--real`. |
| `php artisan request-tracer:undo <trace>`          | Take back what a real replay changed.                                                                                    |
| `php artisan request-tracer:token`                 | Make a signed header for one request (`--ttl=`, `--user=`, `--route=`).                                                  |
| `php artisan request-tracer:prune`                 | Delete old traces (scheduled automatically).                                                                             |
| `php artisan request-tracer:install`               | Optional: publish the config and assets.                                                                                 |




## Examples

**Run a failed request again, with a full trace**

```bash
php artisan request-tracer:replay --journal=failed
```

**Replay a request as a user, sending a body**

```bash
php artisan request-tracer:replay "POST /orders" --data='{"items":[1,2]}' --as=42
```

**Run it for real, then undo it**

```bash
php artisan request-tracer:replay "POST /orders" --data='{"items":[1,2]}' --as=42 --real
php artisan request-tracer:undo 01J8DEMO0RDER0FL0W00000001
```

**Trace one request on a server, without the listen command**

```bash
php artisan request-tracer:token --ttl=300 --route='api/orders*'
# then send it:  curl -H "X-Request-Tracer: <token>" https://example.com/api/orders
```

**Trace something outside** `app/`**, or leave a class out**

```php
use Ahmedhsieb\RequestTracer\Attributes\Trace;
use Ahmedhsieb\RequestTracer\Attributes\NoTrace;

#[Trace(deep: true)]   // this class: input, output and line by line
class Money { /* ... */ }

#[NoTrace]             // never traced
class HeavyReport { /* ... */ }
```

**Show the tenant or module next to each request**

```php
// App\Providers\AppServiceProvider::boot()
use Ahmedhsieb\RequestTracer\Facades\Tracer;

Tracer::resolveTenantUsing(fn ($request) => $request?->header('X-Tenant'));
Tracer::resolveModuleUsing(fn ($request) => $request?->segment(1));
```

---



## In production

Same two steps, on the server. The dashboard stays closed until you say who may open it:

```php
Gate::define('viewRequestTracer', fn ($user) => in_array($user->email, ['ops@example.com']));
```

- **Secrets are masked before they are stored**, by field name and by shape (`Bearer ...`). The raw value never reaches the database. The one exception is the encrypted, short-lived undo log of a real replay.
- **A failure inside the tracer never breaks a request**; it is logged and swallowed.
- **Nothing records on its own**: only while `request-tracer:listen` runs, or for a signed header, a watched user, a sample, or a replay.
- To trace without the command: [signed header, watched user, sampling](docs/07-running-in-production.md).



## What it costs

Nothing measurable when nobody listens. While listening, a request of 47 steps and 15 queries takes about 5 ms longer with input and output for every call, about 7.5 ms with every line as well, and 7 to 10 ms more after the response was sent. Method and caveats in [docs/benchmarks.md](docs/benchmarks.md).

## Honest limits

Proxies cannot see `new`, final classes, private or static methods. Line-by-line tracing compiles a copy of each file of `code.paths` the first time it is loaded while somebody listens; a long-running worker (Octane, `queue:work`) keeps what it loaded before you started listening until it restarts. Masking is name based. The package is `0.x`. More in [How it compares](docs/12-comparison.md#what-request-tracer-costs-you).

## Documentation

[Introduction](docs/01-introduction.md) · [Installation](docs/02-installation.md) · [Quick start](docs/03-quick-start.md) · [Configuration](docs/04-configuration.md) · [How capture works](docs/05-how-capture-works.md) · [Deep trace](docs/06-deep-trace-guide.md) · [Production](docs/07-running-in-production.md) · [Large applications](docs/08-large-applications.md) · [Using the dashboard](docs/09-using-the-dashboard.md) · [Jobs and commands](docs/10-queues-commands-scheduled-tasks.md) · [Extending](docs/11-extending.md) · [Comparison](docs/12-comparison.md) · [Troubleshooting and upgrading](docs/13-troubleshooting-faq-upgrade.md) · [JSON API](docs/api.md) · [Decisions](docs/decisions.md) · [Security](SECURITY.md) · [Changelog](CHANGELOG.md)

## License

MIT. 

See [LICENSE](LICENSE). العربية: [README.ar.md](README.ar.md)