# Benchmarks

What does the package cost? The honest answer is "nothing when it is idle, a few milliseconds per recorded request, paid mostly *after* the response was sent". This page shows how that was measured, the numbers, and what they do not tell you.

## How to run it

```bash
php benchmarks/run.php                      # all scenarios, 300 requests each
php benchmarks/run.php --requests=1000      # more requests, steadier numbers
php benchmarks/run.php --scenario=deep      # one scenario, JSON output
```

The script boots the example "Order flow" application from `tests/Fixtures` (authentication, validation, several services, a caught exception, an HTTP call, an event and three tables written in a transaction; each request produces a trace of 47 steps and 15 queries) and sends `POST /api/orders` through Laravel's HTTP kernel in the same process. Each scenario runs in its own PHP process. **handle** is the time until the response exists, which is what a user waits for. **terminate** is the work the package postpones until after the response was sent.

It needs the development dependencies (it uses Testbench to boot the application), so it is a tool for this repository, not something that ships to your application.

## Results

PHP 8.4.15, Laravel 13.35, Windows 11 laptop, SQLite file database, 500 requests per scenario after 30 warm-up requests. The table is one run on an otherwise idle machine; the ranges in the text are over five runs.

| Scenario | handle median | handle p95 | terminate median | overhead vs. not installed | peak memory |
|---|---:|---:|---:|---:|---:|
| Package not installed | 13.95 ms | 17.19 ms | 0.13 ms | - | 36 MB |
| Installed, `enabled = false` | 13.92 ms | 18.14 ms | 0.13 ms | -0.03 ms | 38 MB |
| Installed, `triggered` mode, nothing triggers it | 14.13 ms | 17.91 ms | 0.14 ms | +0.18 ms | 40 MB |
| Recording every request: framework collectors + container proxies | 19.23 ms | 23.68 ms | 7.69 ms | +5.28 ms | 54 MB |
| Recording every request: + Deep trace of `OrderService` | 20.25 ms | 25.18 ms | 8.71 ms | +6.30 ms | 52 MB |

Decision cost on a request nothing triggers (triggered mode, measured in isolation, array cache for the watch list): **21 to 28 µs**.

Each recorded request of the Deep trace scenario stores 47 steps, 15 queries and 31 line rows; the benchmark checks that these line rows exist, so the Deep trace row measures Deep trace, not a scenario where it silently did not apply.

### Reading the table

- **Installed but idle costs nothing you can measure.** The differences in the first three rows are inside the noise of a laptop (between -1 and +1 ms from run to run, in both directions). The precise figure is the decision itself: about 25 µs per request, which is one header lookup, one cache read for the watch list and a config read. With `enabled = false` no listener, middleware, autoloader or container hook is registered at all.
- **Recording adds about 4.5 to 5.5 ms to the response** for this flow (47 steps, 15 queries) with the framework collectors and container proxies, and about **1 to 2 ms more** with Deep trace on one class (6 to 7 ms in total). Deep trace costs in proportion to the lines that run, not to the number of steps, so measure it on your own code.
- **Another 7 to 10 ms is spent after the response was sent**, in `terminate()`: building the trace, masking and compressing payloads and writing five tables in chunks. The user does not wait for it, but the PHP worker is busy for that long. With `storage.queue.enabled` the write moves to a queue worker and the worker is only busy for the time it takes to serialise and dispatch the job.
- **Memory**: about +16 MB peak while recording, all of it released at the end of the request (see the leak test in `tests/Order/HardeningTest.php`).

## What this does *not* tell you

- It is an in-process measurement: no web server, no network, a local SQLite file. The queries here take tens of microseconds. In an application whose requests spend 200 ms in a real database, the *absolute* overhead stays about the same (it follows the number of steps, queries and events, not the request time), so the *relative* overhead is smaller than the percentages above.
- The overhead scales with what happens in the request. A request with 5 000 steps costs more than one with 50, which is why `limits.max_spans`, `limits.collapse_after` and `deep_trace.max_hits` exist (see [Running in production](07-running-in-production.md)).
- A different database for the traces changes the `terminate` time (a remote MySQL is slower than a local file). Use the queue storage for that case.
- The numbers move by about a millisecond from run to run on a laptop. Compare scenarios from the *same* run, or use `--requests=2000` on a quiet machine.

## Production defaults follow from this

In production the package starts in `triggered` mode: the idle cost above is all a request pays. A recording is switched on per request (signed header), per user for a while (watch list) or for a small share of authenticated traffic (sampling, off by default), so the recording cost is only ever paid on requests somebody wanted to look at.
